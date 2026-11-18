<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\AggregateVersionPolicy;
use Manager\Shared\Application\Inbox\Exception\InboxClaimNotVerified;
use Manager\Shared\Application\Inbox\Exception\MissingAggregatePredecessor;
use Manager\Shared\Application\Inbox\Exception\MissingAggregateVersion;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\Inbox\MonotonicAggregateVersionPolicy;
use Manager\Shared\Application\Inbox\StrictSequentialAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\Exception\RetryableIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Inbox\InboxConnectionFactory;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStore;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\Inbox\FixedInboxStoreFactory;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class InboxProcessorTest extends InfrastructureTestCase
{
	private const CONSUMER = 'test.inbox_processor';

	private Connection $connection;
	private EphemeralInboxTable $tables;
	private InboxConsumerValidator $consumerValidator;
	private MySqlInboxStore $store;
	private InboxProcessor $processor;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DB::connection();
		$this->tables = new EphemeralInboxTable($this->connection);
		$this->tables->create();
		$this->tables->createBusinessTable();

		$this->consumerValidator = new InboxConsumerValidator(['test' => $this->tables->inboxTable]);
		$this->store = new MySqlInboxStore($this->connection, $this->tables->inboxTable);
		$this->processor = new InboxProcessor(
			new MySqlTransactionalSession($this->connection),
			new MySqlInboxStoreFactory($this->connection, ['test' => $this->tables->inboxTable]),
			$this->consumerValidator
		);
	}

	protected function tearDown(): void
	{
		$this->tables->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_use_the_identical_default_connection_for_the_inbox_adapters(): void
	{
		$factoryConnection = app(InboxConnectionFactory::class)->connection();
		$sessionConnection = $this->connectionOf(app(TransactionalSession::class));
		$storeConnection = $this->connectionOf($this->store);

		$this->assertSame(DB::connection(), $factoryConnection);
		$this->assertSame($factoryConnection, $sessionConnection);
		$this->assertSame($factoryConnection, $storeConnection);
	}

	#[Test]
	public function it_should_wire_the_test_context_through_the_service_container(): void
	{
		config(['inbox.contexts' => ['test' => $this->tables->inboxTable]]);

		$event = IntegrationEventFixture::envelope();
		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->insertBusinessRow($event, 'container wiring');
		});

		$processor = $this->app->make(InboxProcessor::class);
		$result = $processor->process($event, $subscriber);

		$this->assertSame(InboxProcessingResult::PROCESSED, $result);
		$this->assertSame(1, $this->businessRows()->count());
	}

	#[Test]
	public function it_should_commit_the_inbox_claim_and_the_business_effect_together(): void
	{
		$event = IntegrationEventFixture::envelope();
		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->assertSame($this->connection, DB::connection());
			$this->assertSame(1, $this->connection->transactionLevel());
			$this->insertBusinessRow($event, 'committed');
		});

		$result = $this->processor->process($event, $subscriber);

		$this->assertSame(InboxProcessingResult::PROCESSED, $result);
		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame(0, $this->connection->transactionLevel());

		$row = $this->connection->table($this->tables->inboxTable)
			->where('consumer', self::CONSUMER)
			->where('event_id', $event->eventId)
			->first();

		$this->assertNotNull($row);
		$this->assertNotNull($row->processed_at);
		$this->assertSame(InboxOutcome::PROCESSED->value, $row->outcome);
		$this->assertSame(
			0,
			$this->connection->table($this->tables->inboxTable)
				->whereNull('processed_at')
				->whereNull('outcome')
				->count()
		);
	}

	#[Test]
	public function it_should_roll_back_the_inbox_claim_and_the_business_effect_when_the_subscriber_fails(): void
	{
		$event = IntegrationEventFixture::envelope();
		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->insertBusinessRow($event, 'rolled back');

			throw new RuntimeException('subscriber failed');
		});

		$caught = null;

		try {
			$this->processor->process($event, $subscriber);
		} catch (RuntimeException $exception) {
			$caught = $exception;
		}

		$this->assertNotNull($caught);
		$this->assertSame('subscriber failed', $caught->getMessage());
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(
			0,
			$this->connection->table($this->tables->inboxTable)->where('event_id', $event->eventId)->count()
		);
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_the_inbox_claim_and_the_business_effect_when_mark_processed_fails(): void
	{
		$event = IntegrationEventFixture::envelope();
		$failingStore = new class($this->store) implements InboxStore {
			public function __construct(private readonly InboxStore $store) {}

			public function hasHandled(string $consumer, string $eventId): bool
			{
				return $this->store->hasHandled($consumer, $eventId);
			}

			public function claim(IntegrationEventEnvelope $event, string $consumer, DateTimeImmutable $createdAt): bool
			{
				return $this->store->claim($event, $consumer, $createdAt);
			}

			public function markProcessed(
				string $consumer,
				string $eventId,
				InboxOutcome $outcome,
				DateTimeImmutable $processedAt
			): void {
				throw new RuntimeException('mark processed failed');
			}

			public function find(string $consumer, string $eventId): ?InboxRecord
			{
				return $this->store->find($consumer, $eventId);
			}

			public function lastProcessedAggregateVersion(
				string $consumer,
				string $aggregateType,
				string $aggregateId
			): ?int {
				return $this->store->lastProcessedAggregateVersion($consumer, $aggregateType, $aggregateId);
			}
		};

		$processor = new InboxProcessor(
			new MySqlTransactionalSession($this->connection),
			new FixedInboxStoreFactory($failingStore),
			$this->consumerValidator
		);

		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->insertBusinessRow($event, 'must roll back');
		});

		$caught = null;

		try {
			$processor->process($event, $subscriber);
		} catch (RuntimeException $exception) {
			$caught = $exception;
		}

		$this->assertNotNull($caught);
		$this->assertSame('mark processed failed', $caught->getMessage());
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(
			0,
			$this->connection->table($this->tables->inboxTable)->where('event_id', $event->eventId)->count()
		);
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_return_duplicate_and_not_repeat_the_business_effect_for_a_second_delivery(): void
	{
		$event = IntegrationEventFixture::envelope();
		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->insertBusinessRow($event, 'once');
		});

		$first = $this->processor->process($event, $subscriber);
		$second = $this->processor->process($event, $subscriber);

		$this->assertSame(InboxProcessingResult::PROCESSED, $first);
		$this->assertSame(InboxProcessingResult::DUPLICATE, $second);
		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame(
			1,
			$this->connection->table($this->tables->inboxTable)->where('event_id', $event->eventId)->count()
		);
	}

	#[Test]
	public function it_should_not_treat_a_committed_incomplete_claim_as_a_safe_duplicate(): void
	{
		$event = IntegrationEventFixture::envelope();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->connection->table($this->tables->inboxTable)->insert([
			'id' => SimpleUuid::random()->value(),
			'consumer' => self::CONSUMER,
			'event_id' => $event->eventId,
			'event_name' => $event->eventName,
			'event_version' => $event->eventVersion,
			'aggregate_type' => $event->aggregateType,
			'aggregate_id' => $event->aggregateId,
			'aggregate_version' => $event->aggregateVersion,
			'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
			'processed_at' => null,
			'outcome' => null,
			'correlation_id' => null,
			'causation_id' => null,
			'created_at' => $now->format('Y-m-d H:i:s'),
		]);

		$subscriber = $this->subscriber(function (IntegrationEventEnvelope $event): void {
			$this->insertBusinessRow($event, 'must not run');
		});

		$this->expectException(InboxClaimNotVerified::class);

		try {
			$this->processor->process($event, $subscriber);
		} finally {
			$this->assertSame(0, $this->businessRows()->count());
			$this->assertSame(0, $this->connection->transactionLevel());

			$row = $this->connection->table($this->tables->inboxTable)
				->where('consumer', self::CONSUMER)
				->where('event_id', $event->eventId)
				->first();

			$this->assertNotNull($row);
			$this->assertNull($row->processed_at);
			$this->assertNull($row->outcome);
		}
	}

	#[Test]
	public function it_should_record_a_stale_event_without_overwriting_newer_state(): void
	{
		$newerEvent = $this->event(3);
		$staleEvent = $this->event(2);
		$processed = 0;
		$subscriber = $this->subscriber(
			function (IntegrationEventEnvelope $event) use (&$processed): void {
				$processed++;
				$this->insertBusinessRow($event, 'version ' . $event->aggregateVersion);
			},
			new MonotonicAggregateVersionPolicy()
		);

		$this->assertSame(InboxProcessingResult::PROCESSED, $this->processor->process($newerEvent, $subscriber));
		$this->assertSame(InboxProcessingResult::STALE, $this->processor->process($staleEvent, $subscriber));
		$this->assertSame(1, $processed);
		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame('version 3', $this->businessRows()->value('note'));

		$staleRow = $this->connection->table($this->tables->inboxTable)
			->where('consumer', self::CONSUMER)
			->where('event_id', $staleEvent->eventId)
			->first();

		$this->assertNotNull($staleRow);
		$this->assertNotNull($staleRow->processed_at);
		$this->assertSame(InboxOutcome::STALE->value, $staleRow->outcome);
		$this->assertSame(InboxProcessingResult::DUPLICATE, $this->processor->process($staleEvent, $subscriber));
		$this->assertSame(1, $processed);
	}

	#[Test]
	public function it_should_ignore_stale_rows_when_calculating_the_last_processed_version(): void
	{
		$processedEvent = $this->event(3);
		$staleEvent = $this->event(9);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->store->claim($processedEvent, self::CONSUMER, $now);
		$this->store->markProcessed(self::CONSUMER, $processedEvent->eventId, InboxOutcome::PROCESSED, $now);
		$this->store->claim($staleEvent, self::CONSUMER, $now);
		$this->store->markProcessed(self::CONSUMER, $staleEvent->eventId, InboxOutcome::STALE, $now);

		$this->assertSame(
			3,
			$this->store->lastProcessedAggregateVersion(self::CONSUMER, 'stock_item', IntegrationEventFixture::AGGREGATE_ID)
		);
		$this->assertNull($this->store->lastProcessedAggregateVersion(self::CONSUMER, 'stock_item', 'another-aggregate'));
	}

	#[Test]
	public function it_should_roll_back_the_claim_when_a_predecessor_is_missing(): void
	{
		$subscriber = $this->subscriber(
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event, 'processed');
			},
			new StrictSequentialAggregateVersionPolicy()
		);
		$firstEvent = $this->event(3);
		$gapEvent = $this->event(5);

		$this->assertSame(InboxProcessingResult::PROCESSED, $this->processor->process($firstEvent, $subscriber));

		try {
			$this->processor->process($gapEvent, $subscriber);
			$this->fail('A version gap must fail with a missing-predecessor error.');
		} catch (MissingAggregatePredecessor $error) {
			$this->assertInstanceOf(RetryableIntegrationEventFailure::class, $error);
			$this->assertStringContainsString('at version <5>', $error->getMessage());
			$this->assertStringContainsString('last processed version is <3>', $error->getMessage());
		}

		$this->assertSame(1, $this->businessRows()->count());
		$this->assertSame(
			0,
			$this->connection->table($this->tables->inboxTable)->where('event_id', $gapEvent->eventId)->count()
		);
		$this->assertSame(0, $this->connection->transactionLevel());
		$this->assertSame(
			3,
			$this->store->lastProcessedAggregateVersion(self::CONSUMER, $gapEvent->aggregateType, $gapEvent->aggregateId)
		);
	}

	#[Test]
	public function it_should_reject_a_missing_version_under_strict_sequencing(): void
	{
		$event = $this->event(null);
		$subscriber = $this->subscriber(
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event, 'must not run');
			},
			new StrictSequentialAggregateVersionPolicy()
		);

		try {
			$this->processor->process($event, $subscriber);
			$this->fail('Strict sequencing must reject an event without an aggregate version.');
		} catch (MissingAggregateVersion $error) {
			$this->assertInstanceOf(PermanentIntegrationEventFailure::class, $error);
		}

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(
			0,
			$this->connection->table($this->tables->inboxTable)->where('event_id', $event->eventId)->count()
		);
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_process_events_without_versions_without_inventing_ordering(): void
	{
		$subscriber = $this->subscriber(
			function (IntegrationEventEnvelope $event): void {
				$this->insertBusinessRow($event, 'processed without version');
			},
			new MonotonicAggregateVersionPolicy()
		);
		$firstEvent = $this->event(null);
		$secondEvent = $this->event(null);

		$this->assertSame(InboxProcessingResult::PROCESSED, $this->processor->process($firstEvent, $subscriber));
		$this->assertSame(InboxProcessingResult::PROCESSED, $this->processor->process($secondEvent, $subscriber));
		$this->assertSame(2, $this->businessRows()->count());
		$this->assertNull(
			$this->store->lastProcessedAggregateVersion(self::CONSUMER, $firstEvent->aggregateType, $firstEvent->aggregateId)
		);
	}

	private function subscriber(
		callable $handler,
		?AggregateVersionPolicy $orderingPolicy = null
	): TestIntegrationEventSubscriber {
		return new TestIntegrationEventSubscriber(
			self::CONSUMER,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			$handler,
			$orderingPolicy
		);
	}

	private function event(?int $aggregateVersion): IntegrationEventEnvelope
	{
		return IntegrationEventFixture::envelope([
			'eventId' => SimpleUuid::random()->value(),
			'aggregateVersion' => $aggregateVersion,
		]);
	}

	private function insertBusinessRow(IntegrationEventEnvelope $event, string $note): void
	{
		$this->connection->table($this->tables->businessTable)->insert([
			'id' => $event->eventId,
			'note' => $note,
		]);
	}

	private function businessRows(): Builder
	{
		return $this->connection->table($this->tables->businessTable);
	}

	private function connectionOf(object $adapter): Connection
	{
		$property = new ReflectionProperty($adapter, 'connection');

		/** @var Connection $connection */
		$connection = $property->getValue($adapter);

		return $connection;
	}
}
