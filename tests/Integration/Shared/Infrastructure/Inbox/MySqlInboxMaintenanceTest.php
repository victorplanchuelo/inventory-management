<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxMaintenance;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlInboxMaintenanceTest extends InfrastructureTestCase
{
	private const ALPHA_CONSUMER = 'alpha.stock_projection';
	private const BETA_CONSUMER = 'alpha.order_projection';
	private const OTHER_CONTEXT_CONSUMER = 'beta.stock_projection';

	private Connection $connection;
	private EphemeralInboxTable $alpha;
	private EphemeralInboxTable $beta;
	private MySqlInboxMaintenance $maintenance;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DB::connection();
		$this->alpha = new EphemeralInboxTable($this->connection);
		$this->beta = new EphemeralInboxTable($this->connection);
		$this->alpha->create();
		$this->beta->create();

		$this->maintenance = new MySqlInboxMaintenance($this->connection, [
			'alpha' => $this->alpha->inboxTable,
			'beta' => $this->beta->inboxTable,
		]);
	}

	protected function tearDown(): void
	{
		$this->beta->drop();
		$this->alpha->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_snapshot_counts_consumers_and_completion_timestamps(): void
	{
		$now = $this->now();
		$oldest = $now->modify('-3 days');
		$newest = $now->modify('-1 day');

		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $oldest->modify('+1 day'));
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $newest);
		$this->seedInboxRow($this->alpha, self::BETA_CONSUMER, 'stale', $oldest);
		$this->seedInboxRow($this->alpha, self::BETA_CONSUMER, null, null);

		$snapshot = $this->maintenance->snapshot('alpha', $now->modify('-7 days'));

		$this->assertSame('alpha', $snapshot->context);
		$this->assertSame(4, $snapshot->totalRecords);
		$this->assertSame(2, $snapshot->processedRecords);
		$this->assertSame(1, $snapshot->staleRecords);
		$this->assertSame([
			self::BETA_CONSUMER => 2,
			self::ALPHA_CONSUMER => 2,
		], $snapshot->recordsByConsumer);
		$this->assertNotNull($snapshot->oldestProcessedAt);
		$this->assertSame($oldest->format('Y-m-d H:i:s'), $snapshot->oldestProcessedAt->format('Y-m-d H:i:s'));
		$this->assertNotNull($snapshot->newestProcessedAt);
		$this->assertSame($newest->format('Y-m-d H:i:s'), $snapshot->newestProcessedAt->format('Y-m-d H:i:s'));
		$this->assertSame(0, $snapshot->purgeableRecords);
	}

	#[Test]
	public function it_should_not_evaluate_purgeable_records_without_a_purge_cutoff(): void
	{
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$snapshot = $this->maintenance->snapshot('alpha', null);

		$this->assertNull($snapshot->purgeableRecords);
	}

	#[Test]
	public function it_should_purge_only_committed_records_older_than_the_cutoff(): void
	{
		$now = $this->now();
		$oldProcessed = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-8 days'));
		$oldStale = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'stale', $now->modify('-9 days'));
		$recentProcessed = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-1 day'));
		$oldIncomplete = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, null, null, $now->modify('-10 days'));

		$purged = $this->maintenance->purgeProcessedBefore('alpha', $now->modify('-7 days'), 100);

		$this->assertSame(2, $purged);
		$this->assertNull($this->row($this->alpha, $oldProcessed));
		$this->assertNull($this->row($this->alpha, $oldStale));
		$this->assertNotNull($this->row($this->alpha, $recentProcessed));
		$this->assertNotNull($this->row($this->alpha, $oldIncomplete));
	}

	#[Test]
	public function it_should_purge_in_oldest_first_bounded_batches(): void
	{
		$now = $this->now();
		$oldest = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-10 days'));
		$middle = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-9 days'));
		$newest = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-8 days'));

		$this->assertSame(2, $this->maintenance->purgeProcessedBefore('alpha', $now->modify('-7 days'), 2));
		$this->assertNull($this->row($this->alpha, $oldest));
		$this->assertNull($this->row($this->alpha, $middle));
		$this->assertNotNull($this->row($this->alpha, $newest));

		$this->assertSame(1, $this->maintenance->purgeProcessedBefore('alpha', $now->modify('-7 days'), 2));
		$this->assertNull($this->row($this->alpha, $newest));
	}

	#[Test]
	public function it_should_keep_contexts_isolated_when_purging(): void
	{
		$now = $this->now();
		$alphaRow = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-10 days'));
		$betaRow = $this->seedInboxRow($this->beta, self::OTHER_CONTEXT_CONSUMER, 'processed', $now->modify('-10 days'));

		$this->assertSame(1, $this->maintenance->purgeProcessedBefore('alpha', $now->modify('-7 days'), 100));

		$this->assertNull($this->row($this->alpha, $alphaRow));
		$this->assertNotNull($this->row($this->beta, $betaRow));
	}

	#[Test]
	public function it_should_report_configured_contexts(): void
	{
		$this->assertSame(['alpha', 'beta'], $this->maintenance->contexts());
	}

	#[Test]
	public function it_should_reject_an_unknown_context(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->maintenance->snapshot('unknown', null);
	}

	#[Test]
	public function it_should_reject_a_non_positive_purge_limit(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->maintenance->purgeProcessedBefore('alpha', $this->now(), 0);
	}

	private function seedInboxRow(
		EphemeralInboxTable $tables,
		string $consumer,
		?string $outcome,
		?DateTimeImmutable $processedAt,
		?DateTimeImmutable $createdAt = null,
	): string {
		$eventId = SimpleUuid::random()->value();
		$createdAt ??= $this->now();

		$row = [
			'id' => SimpleUuid::random()->value(),
			'consumer' => $consumer,
			'event_id' => $eventId,
			'event_name' => 'inventory.stock_added',
			'event_version' => '1',
			'aggregate_type' => 'stock_item',
			'aggregate_id' => 'stock-item-A-1',
			'aggregate_version' => 1,
			'occurred_at' => $createdAt->format('Y-m-d H:i:s'),
			'processed_at' => $processedAt?->format('Y-m-d H:i:s'),
			'outcome' => $outcome,
			'correlation_id' => null,
			'causation_id' => null,
			'created_at' => $createdAt->format('Y-m-d H:i:s'),
		];

		$this->connection->table($tables->inboxTable)->insert($row);

		return $eventId;
	}

	private function row(EphemeralInboxTable $tables, string $eventId): ?object
	{
		return $this->connection->table($tables->inboxTable)->where('event_id', $eventId)->first();
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
