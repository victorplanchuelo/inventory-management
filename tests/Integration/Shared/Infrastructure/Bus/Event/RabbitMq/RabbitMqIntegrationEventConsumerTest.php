<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception\IntegrationEventPublicationFailed;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventEnvelopeDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventFailurePublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedIntegrationEventPublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqIntegrationEventConsumer;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Inbox\InboxLogger;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\CollectingLogger;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class RabbitMqIntegrationEventConsumerTest extends InfrastructureTestCase
{
	/**
	 * A sub-microsecond timeout is deliberately shorter than a broker round trip. It forces the
	 * real AMQPChannel::waitForConfirm() path to reach its timeout instead of a mocked result.
	 */
	private const TIMEOUT_CONFIRMATION_SECONDS = 0.000000001;

	private Connection $connection;
	private EphemeralInboxTable $tables;
	private RabbitMqConnection $rabbitMqConnection;
	private IntegrationEventQueueNameFormatter $formatter;
	private RabbitMqIntegrationEventConsumer $consumer;
	private TestIntegrationEventSubscriber $subscriber;
	private CollectingLogger $logger;
	private IntegrationEventFailurePublisher $failurePublisher;
	private string $exchangeName;

	protected function setUp(): void
	{
		parent::setUp();

		$suffix = bin2hex(random_bytes(5));

		$this->connection = DB::connection();
		$this->tables = new EphemeralInboxTable($this->connection);
		$this->tables->create();
		$this->tables->createBusinessTable();

		$this->rabbitMqConnection = $this->service(RabbitMqConnection::class);
		$this->formatter = new IntegrationEventQueueNameFormatter();
		$this->exchangeName = 'integration_events_consumer_probe_' . $suffix;
		$this->subscriber = $this->subscriber('test.consumer_' . $suffix, static function (): void {});
		$this->logger = new CollectingLogger();
		$this->failurePublisher = $this->service(IntegrationEventFailurePublisher::class);
		$this->consumer = $this->consumer();

		(new IntegrationEventRabbitMqConfigurer($this->rabbitMqConnection, $this->exchangeName))
			->configure($this->subscriber);
	}

	protected function tearDown(): void
	{
		foreach ($this->queueNames() as $queueName) {
			$this->rabbitMqConnection->queue($queueName)->delete();
		}

		foreach ($this->exchangeNames() as $exchangeName) {
			$this->rabbitMqConnection->exchange($exchangeName)->delete();
		}

		$this->tables->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_return_false_when_the_queue_is_empty(): void
	{
		$this->assertFalse($this->consumer->consumeOne($this->subscriber, $this->queueName()));
	}

	#[Test]
	public function it_should_ack_an_event_only_after_the_inbox_claim_and_business_effect_commit(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event);
			}
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertFalse($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame(0, $this->connection->transactionLevel());

		$row = $this->inboxRow($this->subscriber->consumerName(), $eventId);

		$this->assertNotNull($row);
		$this->assertNotNull($row->processed_at);
		$this->assertSame('processed', $row->outcome);
	}

	#[Test]
	public function it_should_log_a_malformed_event_and_route_it_to_retry(): void
	{
		$this->publish('{"eventId":');

		$this->assertTrue($this->consumer->consumeOne($this->subscriber, $this->queueName()));
		$this->assertContains('inbox.event.malformed', array_column($this->logger->records(), 'message'));
		$this->assertSame(0, $this->businessRows()->count());

		$this->eventually(fn () => $this->assertSame(1, $this->retryQueue()->declareQueue()));
	}

	#[Test]
	public function it_should_retry_after_the_fixed_one_second_delay_and_then_process_the_event(): void
	{
		$eventId = $this->randomEventId();
		$attempts = 0;
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			function (IntegrationEventEnvelope $event) use (&$attempts): void {
				$attempts++;
				$this->insertBusinessRow($event);

				if ($attempts === 1) {
					throw new RuntimeException('transient failure');
				}
			}
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(1, $attempts);
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($this->subscriber->consumerName(), $eventId));
		$this->assertSame(0, $this->connection->transactionLevel());

		$this->assertSame(1, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->queue()->declareQueue());

		usleep(250000);

		$this->assertSame(1, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->queue()->declareQueue());

		sleep(1);

		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->queue()->declareQueue());

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(2, $attempts);
		$this->assertSame(1, $this->businessRows()->count());

		$row = $this->inboxRow($this->subscriber->consumerName(), $eventId);

		$this->assertNotNull($row);
		$this->assertNotNull($row->processed_at);
		$this->assertSame('processed', $row->outcome);
	}

	#[Test]
	public function it_should_dead_letter_after_the_initial_delivery_and_five_retries(): void
	{
		$eventId = $this->randomEventId();
		$messageId = 'message-' . $eventId;
		$body = IntegrationEventFixture::json(['eventId' => $eventId]);
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event);

				throw new RuntimeException('permanent failure');
			}
		);

		$this->publish($body, $messageId);

		for ($attempt = 1; $attempt <= 6; $attempt++) {
			$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));

			if ($attempt < 6) {
				usleep(1200000);
			}
		}

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($this->subscriber->consumerName(), $eventId));
		$this->assertSame(0, $this->queue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame($body, $deadLetter->getBody());
		$this->assertSame($messageId, $deadLetter->getMessageId());
		$this->assertSame(5, (int) ($deadLetter->getHeaders()['redelivery_count'] ?? 0));
	}

	#[Test]
	public function it_should_not_ack_when_failure_routing_cannot_be_confirmed(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			static function (): void {
				throw new RuntimeException('subscriber failure');
			}
		);
		$failingPublisher = new class() implements IntegrationEventFailurePublisher {
			public function publish(string $exchangeName, string $routingKey, string $body, array $properties): void
			{
				throw new RuntimeException('publication failed');
			}
		};
		$consumer = $this->consumer($failingPublisher);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		try {
			$consumer->consumeOne($subscriber, $this->queueName());
			$this->fail('The consumer must fail when failure routing cannot be confirmed.');
		} catch (RuntimeException $error) {
			$this->assertSame('publication failed', $error->getMessage());
		}

		$this->queue()->recover(true);

		$this->eventually(fn () => $this->assertSame(1, $this->queue()->declareQueue()));
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->deadLetterQueue()->declareQueue());
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($this->subscriber->consumerName(), $eventId));
		$this->assertNotContains('inbox.event.retry', array_column($this->logger->records(), 'message'));
	}

	#[Test]
	public function it_should_not_ack_the_original_delivery_when_failure_publication_times_out(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			static function (): void {
				throw new RuntimeException('subscriber failure');
			}
		);
		$configuration = config('queue.connections.rabbitmq');

		$this->assertIsArray($configuration);

		$consumer = $this->consumer(
			new RabbitMqConfirmedIntegrationEventPublisher($configuration, self::TIMEOUT_CONFIRMATION_SECONDS)
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$caught = null;

		try {
			$consumer->consumeOne($subscriber, $this->queueName());
		} catch (IntegrationEventPublicationFailed $error) {
			$caught = $error;
		}

		$this->assertInstanceOf(IntegrationEventPublicationFailed::class, $caught);
		$this->assertSame('RabbitMQ could not confirm the integration-event publication.', $caught->getMessage());

		// The retry copy reached the broker, but the original delivery was not acknowledged.
		$this->eventually(fn () => $this->assertSame(1, $this->retryQueue()->declareQueue()));

		$this->queue()->recover(true);

		$this->eventually(fn () => $this->assertSame(1, $this->queue()->declareQueue()));
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($this->subscriber->consumerName(), $eventId));
		$this->assertNotContains('inbox.event.retry', array_column($this->logger->records(), 'message'));
	}

	private function consumer(?IntegrationEventFailurePublisher $failurePublisher = null): RabbitMqIntegrationEventConsumer
	{
		return new RabbitMqIntegrationEventConsumer(
			$this->rabbitMqConnection,
			new IntegrationEventEnvelopeDeserializer(),
			$this->processor(),
			new InboxLogger($this->logger),
			$failurePublisher ?? $this->failurePublisher,
			$this->exchangeName,
			5
		);
	}

	private function processor(): InboxProcessor
	{
		return new InboxProcessor(
			new MySqlTransactionalSession($this->connection),
			new MySqlInboxStoreFactory($this->connection, ['test' => $this->tables->inboxTable]),
			new InboxConsumerValidator(['test' => $this->tables->inboxTable])
		);
	}

	private function subscriber(string $consumerName, callable $handler): TestIntegrationEventSubscriber
	{
		return new TestIntegrationEventSubscriber(
			$consumerName,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			$handler
		);
	}

	private function publish(string $body, string $messageId = 'message-id'): void
	{
		$this->rabbitMqConnection->exchange($this->exchangeName)->publish(
			$body,
			IntegrationEventFixture::EVENT_NAME,
			AMQP_NOPARAM,
			[
				'message_id' => $messageId,
				'content_type' => 'application/json',
				'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
			]
		);
	}

	private function insertBusinessRow(IntegrationEventEnvelope $event): void
	{
		$this->connection->table($this->tables->businessTable)->insert([
			'id' => $event->eventId,
			'note' => 'consumed',
		]);
	}

	private function businessRows(): Builder
	{
		return $this->connection->table($this->tables->businessTable);
	}

	private function inboxRow(string $consumer, string $eventId): ?object
	{
		return $this->connection->table($this->tables->inboxTable)
			->where('consumer', $consumer)
			->where('event_id', $eventId)
			->first();
	}

	private function randomEventId(): string
	{
		return SimpleUuid::random()->value();
	}

	private function queueName(): string
	{
		return $this->formatter->format($this->subscriber);
	}

	private function retryQueue(): AMQPQueue
	{
		return $this->rabbitMqConnection->queue($this->formatter->formatRetry($this->subscriber));
	}

	private function queue(): AMQPQueue
	{
		return $this->rabbitMqConnection->queue($this->queueName());
	}

	private function deadLetterQueue(): AMQPQueue
	{
		return $this->rabbitMqConnection->queue($this->formatter->formatDeadLetter($this->subscriber));
	}

	/**
	 * @return list<string>
	 */
	private function exchangeNames(): array
	{
		return [$this->exchangeName, 'retry-' . $this->exchangeName, 'dead_letter-' . $this->exchangeName, ];
	}

	/**
	 * @return list<string>
	 */
	private function queueNames(): array
	{
		return [
			$this->queueName(),
			$this->formatter->formatRetry($this->subscriber),
			$this->formatter->formatDeadLetter($this->subscriber),
		];
	}
}
