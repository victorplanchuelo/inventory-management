<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Outbox;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedPublisher;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRepository;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisherLogger;
use Manager\Shared\Infrastructure\Outbox\OutboxRetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\CollectingLogger;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class OutboxPublisherTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';
	private const PAYLOAD = '{"orderId":"abc","total":19.90}';

	private Connection $connection;
	private OutboxRecorder $recorder;
	private MySqlOutboxRepository $repository;
	private RabbitMqConfirmedPublisher $rabbitMqPublisher;
	private OutboxPublisher $publisher;
	private CollectingLogger $logger;
	private AMQPConnection $rabbitMqConnection;
	private AMQPChannel $rabbitMqChannel;
	private AMQPExchange $exchange;
	private ?AMQPQueue $queue = null;
	private string $exchangeName;
	private string $queueName;

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->eventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();
		$this->recorder = app(OutboxRecorder::class);

		$configuration = config('queue.connections.rabbitmq');

		$this->assertIsArray($configuration);

		$suffix = bin2hex(random_bytes(6));
		$this->exchangeName = 'test_outbox_publisher_' . $suffix;
		$this->queueName = 'test_outbox_publisher_queue_' . $suffix;

		$this->rabbitMqConnection = new AMQPConnection($configuration);
		$this->rabbitMqConnection->connect();
		$this->rabbitMqChannel = new AMQPChannel($this->rabbitMqConnection);

		$this->exchange = new AMQPExchange($this->rabbitMqChannel);
		$this->exchange->setName($this->exchangeName);
		$this->exchange->setType(AMQP_EX_TYPE_TOPIC);
		$this->exchange->setFlags(AMQP_DURABLE);
		$this->exchange->declareExchange();

		$this->rabbitMqPublisher = new RabbitMqConfirmedPublisher($configuration, $this->exchangeName, 5.0,);
		$this->repository = new MySqlOutboxRepository($this->connection, 60, new OutboxErrorSanitizer());
		$this->logger = new CollectingLogger();

		$this->publisher = new OutboxPublisher(
			$this->repository,
			new OutboxEnvelopeSerializer(),
			$this->rabbitMqPublisher,
			new OutboxRetryPolicy(),
			new OutboxPublisherLogger($this->logger, new OutboxErrorSanitizer()),
			new OutboxErrorSanitizer(),
			'publisher-test',
		);
	}

	protected function tearDown(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		$this->queue?->delete();
		$this->exchange->delete();
		$this->rabbitMqChannel->close();
		$this->rabbitMqConnection->disconnect();

		parent::tearDown();
	}

	#[Test]
	public function it_should_publish_a_claimed_record_and_mark_it_as_published(): void
	{
		$this->queue = $this->declareQueue('orders.order_confirmed');
		$message = $this->append();

		$this->assertSame(1, $this->publisher->publishBatch(10));

		$row = $this->row($message->eventId());

		$this->assertSame('published', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNotNull($row['published_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['last_error']);

		$envelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $envelope);
		$this->assertSame($this->expectedEnvelope($message->eventId()), $envelope->getBody());
		$this->assertSame($message->eventId(), $envelope->getMessageId());
		$this->assertSame('orders.order_confirmed', $envelope->getRoutingKey());
		$this->assertSame(AMQP_DELIVERY_MODE_PERSISTENT, $envelope->getDeliveryMode());
	}

	#[Test]
	public function it_should_log_publication_failures_without_the_payload(): void
	{
		$message = $this->append('{"orderId":"abc","secret":"PAYLOAD_SECRET_MARKER"}');

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$log = $this->logger->json();

		$this->assertStringContainsString('outbox.record.retry_scheduled', $log);
		$this->assertStringContainsString($message->eventId(), $log);
		$this->assertStringContainsString('OutboxPublicationException', $log);
		$this->assertStringContainsString('outbox.batch.summary', $log);
		$this->assertStringNotContainsString('PAYLOAD_SECRET_MARKER', $log);
	}

	#[Test]
	public function it_should_schedule_a_retry_instead_of_publishing_an_unroutable_message(): void
	{
		$message = $this->append();

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNull($row['published_at']);
		$this->assertNotNull($row['next_attempt_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['publisher_id']);
		$this->assertStringContainsString('unroutable', (string) $row['last_error']);
	}

	#[Test]
	public function it_should_schedule_a_retry_instead_of_publishing_a_negatively_acknowledged_message(): void
	{
		$this->queue = $this->declareQueue('orders.order_confirmed', [
			'x-max-length' => 1,
			'x-overflow' => 'reject-publish',
		]);

		$accepted = $this->append();

		$this->assertSame(1, $this->publisher->publishBatch(10));
		$this->assertSame('published', $this->row($accepted->eventId())['status']);

		$rejected = $this->append();

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$row = $this->row($rejected->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNull($row['published_at']);
		$this->assertNotNull($row['next_attempt_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['locked_at']);
		$this->assertStringContainsString('negatively acknowledged', (string) $row['last_error']);

		$message = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $message);
		$this->assertSame($accepted->eventId(), $message->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_transition_to_failed_after_the_tenth_publication_attempt(): void
	{
		$message = $this->append();

		$this->connection->table('outbox')->where('event_id', $message->eventId())->update([
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS - 1,
		]);

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$failed = $this->row($message->eventId());

		$this->assertSame('failed', $failed['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $failed['attempts']);
		$this->assertNotNull($failed['failed_at']);
		$this->assertNull($failed['published_at']);
		$this->assertNull($failed['next_attempt_at']);
		$this->assertNull($failed['claim_token']);
		$this->assertNull($failed['locked_at']);
		$this->assertNull($failed['publisher_id']);
		$this->assertStringContainsString('unroutable', (string) $failed['last_error']);
		$this->assertSame($message->eventId(), $failed['event_id']);
		$this->assertSame(self::PAYLOAD, $failed['payload']);

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$stillFailed = $this->row($message->eventId());

		$this->assertSame('failed', $stillFailed['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $stillFailed['attempts']);
	}

	#[Test]
	public function it_should_requeue_a_failed_record_and_republish_the_same_envelope_bytes(): void
	{
		$message = $this->append();

		$this->connection->table('outbox')->where('event_id', $message->eventId())->update([
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS - 1,
		]);

		$this->assertSame(0, $this->publisher->publishBatch(10));

		$failed = $this->row($message->eventId());

		$this->assertSame('failed', $failed['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $failed['attempts']);

		$this->assertTrue($this->repository->requeueFailed($message->eventId()));

		$requeued = $this->row($message->eventId());

		$this->assertSame('pending', $requeued['status']);
		$this->assertSame(0, (int) $requeued['attempts']);
		$this->assertNull($requeued['failed_at']);
		$this->assertNull($requeued['next_attempt_at']);
		$this->assertSame($failed['last_error'], $requeued['last_error']);

		$this->queue = $this->declareQueue('orders.order_confirmed');

		$this->assertSame(1, $this->publisher->publishBatch(10));

		$published = $this->row($message->eventId());

		$this->assertSame('published', $published['status']);
		$this->assertSame(1, (int) $published['attempts']);

		$envelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $envelope);
		$this->assertSame($this->expectedEnvelope($message->eventId()), $envelope->getBody());
		$this->assertSame($message->eventId(), $envelope->getMessageId());
		$this->assertSame(AMQP_DELIVERY_MODE_PERSISTENT, $envelope->getDeliveryMode());
	}

	#[Test]
	public function it_should_preserve_identity_and_exact_body_bytes_when_an_expired_claim_is_reclaimed_and_republished(): void
	{
		$this->queue = $this->declareQueue('orders.order_confirmed');
		$message = $this->append();

		$this->publisher->publishBatch(10);

		$firstEnvelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $firstEnvelope);

		$expiredLease = (new DateTimeImmutable('-10 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

		$this->connection->table('outbox')->where('event_id', $message->eventId())->update([
			'status' => 'claimed',
			'published_at' => null,
			'next_attempt_at' => null,
			'locked_at' => $expiredLease,
			'claim_token' => 'stale-claim-token',
			'publisher_id' => 'stale-publisher',
			'last_error' => null,
			'failed_at' => null,
		]);

		$this->assertSame(1, $this->publisher->publishBatch(10));

		$secondEnvelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $secondEnvelope);
		$this->assertSame($firstEnvelope->getBody(), $secondEnvelope->getBody());
		$this->assertSame($firstEnvelope->getMessageId(), $secondEnvelope->getMessageId());
		$this->assertSame($message->eventId(), $secondEnvelope->getMessageId());
		$this->assertSame(2, (int) $this->row($message->eventId())['attempts']);
		$this->assertSame('published', $this->row($message->eventId())['status']);
	}

	/** @param array<string, int|string> $arguments */
	private function declareQueue(string $routingKey, array $arguments = []): AMQPQueue
	{
		$queue = new AMQPQueue($this->rabbitMqChannel);
		$queue->setName($this->queueName);
		$queue->setFlags(AMQP_DURABLE);

		foreach ($arguments as $name => $value) {
			$queue->setArgument($name, $value);
		}

		$queue->declareQueue();
		$queue->bind($this->exchangeName, $routingKey);

		return $queue;
	}

	private function append(string $payload = self::PAYLOAD): OutboxMessage
	{
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		$message = new OutboxMessage(
			$eventId,
			'orders.order_confirmed',
			'1',
			'orders',
			'order',
			self::AGGREGATE_ID,
			$payload,
			new DateTimeImmutable('2026-11-10 10:34:56', new DateTimeZone('UTC')),
			7,
			'correlation-1',
			'causation-1',
		);

		$this->recorder->append($message);

		return $message;
	}

	/** @return array<string, mixed> */
	private function row(string $eventId): array
	{
		$row = $this->connection->table('outbox')->where('event_id', $eventId)->first();

		$this->assertNotNull($row);

		/** @var array<string, mixed> $values */
		$values = (array) $row;

		return $values;
	}

	private function expectedEnvelope(string $eventId): string
	{
		return '{"eventId":"' . $eventId . '",'
			. '"eventName":"orders.order_confirmed",'
			. '"eventVersion":"1",'
			. '"aggregateType":"order",'
			. '"aggregateId":"' . self::AGGREGATE_ID . '",'
			. '"aggregateVersion":7,'
			. '"occurredAt":"2026-11-10T10:34:56Z",'
			. '"payload":' . self::PAYLOAD . ','
			. '"correlationId":"correlation-1",'
			. '"causationId":"causation-1"}';
	}
}
