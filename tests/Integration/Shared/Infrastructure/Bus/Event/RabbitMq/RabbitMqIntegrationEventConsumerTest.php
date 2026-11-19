<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPQueue;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\AggregateVersionPolicy;
use Manager\Shared\Application\Inbox\Exception\MissingAggregatePredecessor;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\MonotonicAggregateVersionPolicy;
use Manager\Shared\Application\Inbox\StrictSequentialAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventName;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventVersion;
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
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStore;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\PermanentSubscriberFailure;
use Tests\Shared\Infrastructure\IntegrationEvent\RetryableSubscriberFailure;
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
	public function it_should_log_a_malformed_event_and_dead_letter_it_without_retrying(): void
	{
		$this->publish('{"eventId":');

		$this->assertTrue($this->consumer->consumeOne($this->subscriber, $this->queueName()));
		$this->assertContains('inbox.event.malformed', array_column($this->logger->records(), 'message'));
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame('{"eventId":', $deadLetter->getBody());
		$this->assertSame(InvalidIntegrationEventEnvelope::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertSame($this->subscriber->consumerName(), $deadLetter->getHeaders()['x-consumer']);
		$this->assertSame($this->queueName(), $deadLetter->getHeaders()['x-original-queue']);
		$this->assertArrayNotHasKey('x-event-id', $deadLetter->getHeaders());
		$this->assertArrayNotHasKey('x-event-name', $deadLetter->getHeaders());
		$this->assertSame(0, (int) $deadLetter->getHeaders()['redelivery_count']);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$deadLetter->getHeaders()['x-failed-at']
		);
	}

	#[Test]
	public function it_should_dead_letter_an_unsupported_event_name_with_the_event_headers(): void
	{
		$data = IntegrationEventFixture::data(['eventName' => 'inventory.stock_removed']);

		$this->publish(IntegrationEventFixture::json($data));

		$this->assertTrue($this->consumer->consumeOne($this->subscriber, $this->queueName()));
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());
		$this->assertSame(0, $this->businessRows()->count());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame(UnsupportedIntegrationEventName::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertSame(IntegrationEventFixture::EVENT_ID, $deadLetter->getHeaders()['x-event-id']);
		$this->assertSame('inventory.stock_removed', $deadLetter->getHeaders()['x-event-name']);
		$this->assertNotContains('inbox.event.malformed', array_column($this->logger->records(), 'message'));
	}

	#[Test]
	public function it_should_dead_letter_an_unsupported_event_version_with_the_event_headers(): void
	{
		$data = IntegrationEventFixture::data(['eventVersion' => '2']);

		$this->publish(IntegrationEventFixture::json($data));

		$this->assertTrue($this->consumer->consumeOne($this->subscriber, $this->queueName()));
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());
		$this->assertSame(0, $this->businessRows()->count());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame(UnsupportedIntegrationEventVersion::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertSame(IntegrationEventFixture::EVENT_ID, $deadLetter->getHeaders()['x-event-id']);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $deadLetter->getHeaders()['x-event-name']);
		$this->assertNotContains('inbox.event.malformed', array_column($this->logger->records(), 'message'));
	}

	#[Test]
	public function it_should_dead_letter_a_permanent_subscriber_failure_without_claiming_the_event(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			static function (): void {
				throw new PermanentSubscriberFailure('permanent failure with password=super-secret-value');
			}
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($subscriber->consumerName(), $eventId));
		$this->assertSame(0, $this->connection->transactionLevel());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame(PermanentSubscriberFailure::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertSame($eventId, $deadLetter->getHeaders()['x-event-id']);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $deadLetter->getHeaders()['x-event-name']);
		$this->assertSame($subscriber->consumerName(), $deadLetter->getHeaders()['x-consumer']);
		$this->assertSame($this->queueName(), $deadLetter->getHeaders()['x-original-queue']);
		$this->assertSame(0, (int) $deadLetter->getHeaders()['redelivery_count']);
		$this->assertStringContainsString('[REDACTED]', $deadLetter->getHeaders()['x-error-message']);
		$this->assertStringNotContainsString('super-secret-value', $deadLetter->getHeaders()['x-error-message']);
	}

	#[Test]
	public function it_should_retry_an_explicitly_retryable_subscriber_failure(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			static function (): void {
				throw new RetryableSubscriberFailure('transient failure');
			}
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(1, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->deadLetterQueue()->declareQueue());
		$this->assertNull($this->inboxRow($subscriber->consumerName(), $eventId));
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_dead_letter_an_event_for_an_invalid_consumer_identity(): void
	{
		$eventId = $this->randomEventId();
		$invalidSubscriber = $this->subscriber('other.invalid_consumer', static function (): void {});

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId]));

		$this->assertTrue($this->consumer->consumeOne($invalidSubscriber, $this->queueName()));
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());
		$this->assertNull($this->inboxRow('other.invalid_consumer', $eventId));

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame('other.invalid_consumer', $deadLetter->getHeaders()['x-consumer']);
		$this->assertSame($eventId, $deadLetter->getHeaders()['x-event-id']);
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
		$this->assertSame(RuntimeException::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertSame('permanent failure', $deadLetter->getHeaders()['x-error-message']);
		$this->assertSame($subscriber->consumerName(), $deadLetter->getHeaders()['x-consumer']);
		$this->assertSame($eventId, $deadLetter->getHeaders()['x-event-id']);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $deadLetter->getHeaders()['x-event-name']);
		$this->assertSame($this->queueName(), $deadLetter->getHeaders()['x-original-queue']);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$deadLetter->getHeaders()['x-failed-at']
		);
	}

	#[Test]
	public function it_should_retry_and_dead_letter_an_event_with_a_missing_predecessor(): void
	{
		$consumer = $this->subscriber->consumerName();
		$subscriber = $this->subscriber(
			$consumer,
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event);
			},
			new StrictSequentialAggregateVersionPolicy()
		);
		$eventId = $this->randomEventId();
		$body = IntegrationEventFixture::json(['eventId' => $eventId, 'aggregateVersion' => 5]);

		$this->seedProcessedVersion($consumer, 3);
		$this->publish($body);

		for ($attempt = 1; $attempt <= 6; $attempt++) {
			$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));

			if ($attempt < 6) {
				usleep(1200000);
			}
		}

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertNull($this->inboxRow($consumer, $eventId));
		$this->assertSame(0, $this->queue()->declareQueue());
		$this->assertSame(1, $this->deadLetterQueue()->declareQueue());

		$deadLetter = $this->deadLetterQueue()->get(AMQP_AUTOACK);

		$this->assertNotNull($deadLetter);
		$this->assertSame($body, $deadLetter->getBody());
		$this->assertSame(5, (int) ($deadLetter->getHeaders()['redelivery_count'] ?? 0));
		$this->assertSame(MissingAggregatePredecessor::class, $deadLetter->getHeaders()['x-error-class']);
		$this->assertStringContainsString('last processed version is <3>', $deadLetter->getHeaders()['x-error-message']);
		$this->assertSame($eventId, $deadLetter->getHeaders()['x-event-id']);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $deadLetter->getHeaders()['x-event-name']);
	}

	#[Test]
	public function it_should_ack_a_stale_event_without_retrying_or_invoking_the_subscriber(): void
	{
		$consumer = $this->subscriber->consumerName();
		$subscriber = $this->subscriber(
			$consumer,
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event);
			},
			new MonotonicAggregateVersionPolicy()
		);
		$eventId = $this->randomEventId();

		$this->seedProcessedVersion($consumer, 3);
		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId, 'aggregateVersion' => 2]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->deadLetterQueue()->declareQueue());

		$row = $this->inboxRow($consumer, $eventId);

		$this->assertNotNull($row);
		$this->assertNotNull($row->processed_at);
		$this->assertSame(InboxOutcome::STALE->value, $row->outcome);
		$this->assertContains('inbox.event.stale', array_column($this->logger->records(), 'message'));
		$this->assertNotContains('inbox.event.processed', array_column($this->logger->records(), 'message'));
		$this->assertNotContains('inbox.event.duplicate', array_column($this->logger->records(), 'message'));
	}

	#[Test]
	public function it_should_process_an_event_without_an_aggregate_version_without_invented_ordering(): void
	{
		$eventId = $this->randomEventId();
		$subscriber = $this->subscriber(
			$this->subscriber->consumerName(),
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event);
			},
			new MonotonicAggregateVersionPolicy()
		);

		$this->publish(IntegrationEventFixture::json(['eventId' => $eventId, 'aggregateVersion' => null]));

		$this->assertTrue($this->consumer->consumeOne($subscriber, $this->queueName()));
		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame(0, $this->retryQueue()->declareQueue());
		$this->assertSame(0, $this->deadLetterQueue()->declareQueue());

		$row = $this->inboxRow($subscriber->consumerName(), $eventId);

		$this->assertNotNull($row);
		$this->assertSame(InboxOutcome::PROCESSED->value, $row->outcome);
		$this->assertNull($row->aggregate_version);
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
			new InboxLogger($this->logger, new OutboxErrorSanitizer()),
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

	private function subscriber(
		string $consumerName,
		callable $handler,
		?AggregateVersionPolicy $orderingPolicy = null
	): TestIntegrationEventSubscriber {
		return new TestIntegrationEventSubscriber(
			$consumerName,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			$handler,
			$orderingPolicy
		);
	}

	private function seedProcessedVersion(string $consumer, int $aggregateVersion): void
	{
		$event = IntegrationEventFixture::envelope([
			'eventId' => $this->randomEventId(),
			'aggregateVersion' => $aggregateVersion,
		]);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$store = new MySqlInboxStore($this->connection, $this->tables->inboxTable);

		$store->claim($event, $consumer, $now);
		$store->markProcessed($consumer, $event->eventId, InboxOutcome::PROCESSED, $now);
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
