<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\Exception\InboxClaimNotVerified;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
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

	private function subscriber(callable $handler): TestIntegrationEventSubscriber
	{
		return new TestIntegrationEventSubscriber(
			self::CONSUMER,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			$handler
		);
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
