<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Console\Outbox;

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
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class OutboxCommandsTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	private Connection $connection;
	private OutboxRecorder $recorder;
	private AMQPConnection $rabbitMqConnection;
	private AMQPChannel $rabbitMqChannel;
	private AMQPExchange $exchange;
	private AMQPQueue $queue;
	private string $exchangeName;

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
		$this->exchangeName = 'test_outbox_command_' . $suffix;

		$this->rabbitMqConnection = new AMQPConnection($configuration);
		$this->rabbitMqConnection->connect();
		$this->rabbitMqChannel = new AMQPChannel($this->rabbitMqConnection);

		$this->exchange = new AMQPExchange($this->rabbitMqChannel);
		$this->exchange->setName($this->exchangeName);
		$this->exchange->setType(AMQP_EX_TYPE_TOPIC);
		$this->exchange->setFlags(AMQP_DURABLE);
		$this->exchange->declareExchange();

		$this->queue = new AMQPQueue($this->rabbitMqChannel);
		$this->queue->setName('test_outbox_command_queue_' . $suffix);
		$this->queue->setFlags(AMQP_DURABLE);
		$this->queue->declareQueue();
		$this->queue->bind($this->exchangeName, '#');

		config(['outbox.exchange' => $this->exchangeName]);
	}

	protected function tearDown(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		$this->queue->delete();
		$this->exchange->delete();
		$this->rabbitMqChannel->close();
		$this->rabbitMqConnection->disconnect();

		parent::tearDown();
	}

	#[Test]
	public function it_should_process_a_bounded_batch_and_exit_successfully(): void
	{
		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$this->assertSame(1, $this->publishedCount([$first, $second]));
		$this->assertSame(1, $this->pendingCount([$first, $second]));

		$firstMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $firstMessage);
		$this->assertContains($firstMessage->getMessageId(), [$first->eventId(), $second->eventId()]);

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$secondMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $secondMessage);
		$this->assertNotSame($firstMessage->getMessageId(), $secondMessage->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_use_the_configured_publish_batch_as_the_default_limit(): void
	{
		config(['outbox.publish_batch' => 1]);

		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish')
			->assertSuccessful();

		$this->assertSame(1, $this->publishedCount([$first, $second]));
		$this->assertSame(1, $this->pendingCount([$first, $second]));

		$this->artisan('manager:integration-events:outbox:publish')
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$firstMessage = $this->queue->get(AMQP_AUTOACK);
		$secondMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $firstMessage);
		$this->assertInstanceOf(AMQPEnvelope::class, $secondMessage);
		$this->assertNotSame($firstMessage->getMessageId(), $secondMessage->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_allow_the_limit_option_to_override_the_configured_publish_batch(): void
	{
		config(['outbox.publish_batch' => 1]);

		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 2])
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$this->assertInstanceOf(AMQPEnvelope::class, $this->queue->get(AMQP_AUTOACK));
		$this->assertInstanceOf(AMQPEnvelope::class, $this->queue->get(AMQP_AUTOACK));
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_fail_without_processing_records_when_the_limit_is_not_positive(): void
	{
		$message = $this->append('orders.order_confirmed');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 0])
			->assertFailed();

		$row = $this->connection->table('outbox')->where('event_id', $message->eventId())->first();

		$this->assertNotNull($row);
		$this->assertSame('pending', $row->status);
		$this->assertSame(0, (int) $row->attempts);
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_requeue_a_failed_record_and_publish_the_same_event_identity(): void
	{
		$message = $this->append('orders.order_confirmed');
		$lastError = 'OutboxPublicationException: RabbitMQ confirmation timed out.';

		$this->connection->table('outbox')->where('event_id', $message->eventId())->update([
			'status' => 'failed',
			'attempts' => 10,
			'failed_at' => '2026-11-10 11:55:00',
			'next_attempt_at' => null,
			'locked_at' => '2026-11-10 11:50:00',
			'claim_token' => 'stale-token',
			'publisher_id' => 'stale-publisher',
			'last_error' => $lastError,
		]);

		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => $message->eventId()])
			->assertSuccessful();

		$requeued = $this->row($message->eventId());

		$this->assertSame('pending', $requeued['status']);
		$this->assertSame(0, (int) $requeued['attempts']);
		$this->assertNull($requeued['failed_at']);
		$this->assertNull($requeued['next_attempt_at']);
		$this->assertNull($requeued['locked_at']);
		$this->assertNull($requeued['claim_token']);
		$this->assertNull($requeued['publisher_id']);
		$this->assertSame($lastError, $requeued['last_error']);

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$envelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $envelope);
		$this->assertSame($message->eventId(), $envelope->getMessageId());
		$this->assertSame($this->expectedEnvelope($message), $envelope->getBody());

		$published = $this->row($message->eventId());

		$this->assertSame('published', $published['status']);
		$this->assertSame(1, (int) $published['attempts']);
	}

	#[Test]
	public function it_should_refuse_to_requeue_a_record_that_is_not_failed(): void
	{
		$message = $this->append('orders.order_confirmed');

		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => $message->eventId()])
			->assertFailed();

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(0, (int) $row['attempts']);
	}

	#[Test]
	public function it_should_refuse_to_requeue_an_unknown_event_id(): void
	{
		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => SimpleUuid::random()->value()])
			->assertFailed();
	}

	private function append(string $eventName): OutboxMessage
	{
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		$message = new OutboxMessage(
			$eventId,
			$eventName,
			'1',
			'orders',
			'order',
			self::AGGREGATE_ID,
			'{"orderId":"abc"}',
			new DateTimeImmutable('2026-11-10 10:34:56', new DateTimeZone('UTC')),
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

	private function expectedEnvelope(OutboxMessage $message): string
	{
		return '{"eventId":"' . $message->eventId() . '",'
			. '"eventName":"' . $message->eventName() . '",'
			. '"eventVersion":"1",'
			. '"aggregateType":"order",'
			. '"aggregateId":"' . self::AGGREGATE_ID . '",'
			. '"occurredAt":"2026-11-10T10:34:56Z",'
			. '"payload":{"orderId":"abc"}}';
	}

	/** @param list<OutboxMessage> $messages */
	private function publishedCount(array $messages): int
	{
		return $this->connection->table('outbox')
			->whereIn('event_id', array_map(static fn (OutboxMessage $message): string => $message->eventId(), $messages))
			->where('status', 'published')
			->count();
	}

	/** @param list<OutboxMessage> $messages */
	private function pendingCount(array $messages): int
	{
		return $this->connection->table('outbox')
			->whereIn('event_id', array_map(static fn (OutboxMessage $message): string => $message->eventId(), $messages))
			->where('status', 'pending')
			->count();
	}
}
