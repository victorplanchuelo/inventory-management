<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStore;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlInboxStoreTest extends InfrastructureTestCase
{
	private const CONSUMER = 'test.inbox_store';

	private Connection $connection;
	private EphemeralInboxTable $tables;
	private MySqlInboxStore $store;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DB::connection();
		$this->tables = new EphemeralInboxTable($this->connection);
		$this->tables->create();
		$this->store = new MySqlInboxStore($this->connection, $this->tables->inboxTable);
	}

	protected function tearDown(): void
	{
		$this->tables->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_use_char_36_uuid_columns_and_inherit_the_connection_collation(): void
	{
		$columns = $this->connection->select('SHOW FULL COLUMNS FROM `' . $this->tables->inboxTable . '`');
		$columnsById = [];

		foreach ($columns as $column) {
			$columnsById[$column->Field] = $column;
		}

		$this->assertSame('char(36)', $columnsById['id']->Type);
		$this->assertSame('char(36)', $columnsById['event_id']->Type);

		$table = $this->connection->table('information_schema.TABLES')
			->select('TABLE_COLLATION')
			->where('TABLE_SCHEMA', $this->connection->getDatabaseName())
			->where('TABLE_NAME', $this->tables->inboxTable)
			->first();

		$this->assertNotNull($table);
		$this->assertSame($this->connection->getConfig('collation'), $table->TABLE_COLLATION);
	}

	#[Test]
	public function it_should_claim_an_event_and_only_report_it_as_handled_after_marking_it_processed(): void
	{
		$event = IntegrationEventFixture::envelope();
		$createdAt = new DateTimeImmutable('2026-11-10T12:35:00Z');

		$this->assertTrue($this->store->claim($event, self::CONSUMER, $createdAt));
		$this->assertFalse($this->store->hasHandled(self::CONSUMER, $event->eventId));

		$claimed = $this->store->find(self::CONSUMER, $event->eventId);

		$this->assertNotNull($claimed);
		$this->assertNull($claimed->processedAt);
		$this->assertNull($claimed->outcome);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $claimed->eventName);
		$this->assertSame(IntegrationEventFixture::AGGREGATE_ID, $claimed->aggregateId);
		$this->assertSame('2026-11-10 12:35:00', $claimed->createdAt->format('Y-m-d H:i:s'));

		$this->store->markProcessed(
			self::CONSUMER,
			$event->eventId,
			InboxOutcome::PROCESSED,
			new DateTimeImmutable('2026-11-10T12:35:01Z')
		);

		$this->assertTrue($this->store->hasHandled(self::CONSUMER, $event->eventId));

		$processed = $this->store->find(self::CONSUMER, $event->eventId);

		$this->assertNotNull($processed);
		$this->assertSame(InboxOutcome::PROCESSED, $processed->outcome);
		$this->assertSame('2026-11-10 12:35:01', $processed->processedAt->format('Y-m-d H:i:s'));
	}

	#[Test]
	public function it_should_reject_a_second_claim_for_the_same_consumer_and_event(): void
	{
		$event = IntegrationEventFixture::envelope();
		$this->store->claim($event, self::CONSUMER, new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$claimed = $this->store->claim($event, self::CONSUMER, new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$this->assertFalse($claimed);
		$this->assertSame(
			1,
			$this->connection->table($this->tables->inboxTable)
				->where('consumer', self::CONSUMER)
				->where('event_id', $event->eventId)
				->count()
		);
	}

	#[Test]
	public function it_should_allow_the_same_event_to_be_claimed_by_a_different_consumer(): void
	{
		$event = IntegrationEventFixture::envelope();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->assertTrue($this->store->claim($event, self::CONSUMER, $now));
		$this->assertTrue($this->store->claim($event, 'test.other_consumer', $now));
	}

	#[Test]
	public function it_should_persist_optional_correlation_and_causation_metadata_when_present(): void
	{
		$event = IntegrationEventFixture::envelope();

		$this->store->claim($event, self::CONSUMER, new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$record = $this->store->find(self::CONSUMER, $event->eventId);

		$this->assertNotNull($record);
		$this->assertSame('correlation-1', $record->correlationId);
		$this->assertSame('causation-1', $record->causationId);
	}

	#[Test]
	public function it_should_leave_optional_metadata_null_when_absent(): void
	{
		$data = IntegrationEventFixture::data();
		unset($data['correlationId'], $data['causationId']);
		$event = IntegrationEventEnvelope::fromArray($data);

		$this->store->claim($event, self::CONSUMER, new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$record = $this->store->find(self::CONSUMER, $event->eventId);

		$this->assertNotNull($record);
		$this->assertNull($record->correlationId);
		$this->assertNull($record->causationId);
	}

	#[Test]
	public function it_should_keep_processed_at_and_outcome_consistent(): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->expectException(QueryException::class);

		$this->connection->table($this->tables->inboxTable)->insert([
			...$this->row($now, null),
			'processed_at' => $now->format('Y-m-d H:i:s'),
		]);
	}

	#[Test]
	public function it_should_allow_fully_incomplete_rows_but_never_report_them_as_handled(): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$row = $this->row($now, null);

		$this->connection->table($this->tables->inboxTable)->insert($row);

		$this->assertFalse($this->store->hasHandled(self::CONSUMER, $row['event_id']));
	}

	#[Test]
	public function it_should_rethrow_database_errors_that_are_not_the_consumer_event_id_uniqueness_violation(): void
	{
		$event = IntegrationEventFixture::envelope();

		$this->connection->statement('DROP TABLE `' . $this->tables->inboxTable . '`');

		$this->expectException(QueryException::class);

		$this->store->claim($event, self::CONSUMER, new DateTimeImmutable('now', new DateTimeZone('UTC')));
	}

	#[Test]
	public function it_should_fail_marking_a_claim_that_does_not_exist(): void
	{
		$this->expectException(RuntimeException::class);

		$this->store->markProcessed(
			self::CONSUMER,
			IntegrationEventFixture::EVENT_ID,
			InboxOutcome::PROCESSED,
			new DateTimeImmutable('now', new DateTimeZone('UTC'))
		);
	}

	#[Test]
	public function it_should_return_null_when_no_claim_exists(): void
	{
		$this->assertNull($this->store->find(self::CONSUMER, IntegrationEventFixture::EVENT_ID));
	}

	#[Test]
	public function it_should_drop_the_ephemeral_tables_during_teardown(): void
	{
		$tables = new EphemeralInboxTable($this->connection);
		$tables->create();
		$tables->createBusinessTable();

		$this->assertTrue($tables->exists($tables->inboxTable));
		$this->assertTrue($tables->exists($tables->businessTable));

		$tables->drop();

		$this->assertFalse($tables->exists($tables->inboxTable));
		$this->assertFalse($tables->exists($tables->businessTable));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row(DateTimeImmutable $now, ?InboxOutcome $outcome): array
	{
		return [
			'id' => SimpleUuid::random()->value(),
			'consumer' => self::CONSUMER,
			'event_id' => SimpleUuid::random()->value(),
			'event_name' => IntegrationEventFixture::EVENT_NAME,
			'event_version' => IntegrationEventFixture::EVENT_VERSION,
			'aggregate_type' => 'stock_item',
			'aggregate_id' => IntegrationEventFixture::AGGREGATE_ID,
			'aggregate_version' => 3,
			'occurred_at' => '2026-11-10 12:34:56',
			'processed_at' => $outcome === null ? null : $now->format('Y-m-d H:i:s'),
			'outcome' => $outcome?->value,
			'correlation_id' => null,
			'causation_id' => null,
			'created_at' => $now->format('Y-m-d H:i:s'),
		];
	}
}
