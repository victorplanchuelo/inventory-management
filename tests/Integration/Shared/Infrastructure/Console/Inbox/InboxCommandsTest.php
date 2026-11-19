<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Console\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class InboxCommandsTest extends InfrastructureTestCase
{
	private const ALPHA_CONSUMER = 'alpha.stock_projection';
	private const BETA_CONSUMER = 'beta.stock_projection';

	private Connection $connection;
	private EphemeralInboxTable $alpha;
	private EphemeralInboxTable $beta;

	protected function setUp(): void
	{
		parent::setUp();

		$this->connection = DB::connection();
		$this->alpha = new EphemeralInboxTable($this->connection);
		$this->beta = new EphemeralInboxTable($this->connection);
		$this->alpha->create();
		$this->beta->create();

		config([
			'inbox.contexts' => [
				'alpha' => $this->alpha->inboxTable,
				'beta' => $this->beta->inboxTable,
			],
			'inbox.retention_days' => null,
		]);
	}

	protected function tearDown(): void
	{
		$this->beta->drop();
		$this->alpha->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_report_inbox_status_for_the_selected_context(): void
	{
		$now = $this->now();
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-8 days'));
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-2 days'));
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'stale', $now->modify('-9 days'));
		$this->seedInboxRow($this->alpha, self::BETA_CONSUMER, null, null);
		$this->seedInboxRow($this->beta, self::BETA_CONSUMER, 'processed', $now->modify('-1 day'));

		$this->artisan('manager:integration-events:inbox:status', [
			'--context' => 'alpha',
			'--older-than-days' => 7,
		])
			->expectsOutputToContain('Inbox status')
			->expectsOutputToContain('Retention: 7 day(s)')
			->expectsOutputToContain('Context: alpha')
			->expectsOutputToContain('Total records: 4')
			->expectsOutputToContain('Processed records: 2')
			->expectsOutputToContain('Stale records: 1')
			->expectsOutputToContain(self::ALPHA_CONSUMER . ': 3')
			->expectsOutputToContain(self::BETA_CONSUMER . ': 1')
			->expectsOutputToContain('Purgeable records: 2')
			->doesntExpectOutputToContain('Context: beta')
			->assertSuccessful();
	}

	#[Test]
	public function it_should_report_that_purgeable_records_are_not_evaluated_without_retention(): void
	{
		$this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$this->artisan('manager:integration-events:inbox:status', ['--context' => 'alpha'])
			->expectsOutputToContain('Retention: not configured')
			->expectsOutputToContain('Purgeable records: not evaluated (no retention configured)')
			->assertSuccessful();
	}

	#[Test]
	public function it_should_report_when_no_contexts_are_configured(): void
	{
		config(['inbox.contexts' => []]);

		$this->artisan('manager:integration-events:inbox:status')
			->expectsOutputToContain('No Inbox contexts are configured.')
			->assertSuccessful();
	}

	#[Test]
	public function it_should_reject_an_unknown_context_in_the_status_command(): void
	{
		$this->artisan('manager:integration-events:inbox:status', ['--context' => 'unknown'])
			->expectsOutputToContain('The Inbox context <unknown> is not configured.')
			->assertFailed();
	}

	#[Test]
	public function it_should_reject_an_invalid_retention_in_the_status_command(): void
	{
		$this->artisan('manager:integration-events:inbox:status', ['--older-than-days' => 0])
			->expectsOutputToContain('retention must be a positive integer')
			->assertFailed();
	}

	#[Test]
	public function it_should_purge_only_committed_records_older_than_the_cutoff(): void
	{
		$now = $this->now();
		$oldProcessed = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-8 days'));
		$oldStale = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'stale', $now->modify('-9 days'));
		$recentProcessed = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-1 day'));
		$oldIncomplete = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, null, null, $now->modify('-10 days'));
		$betaProcessed = $this->seedInboxRow($this->beta, self::BETA_CONSUMER, 'processed', $now->modify('-8 days'));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'alpha',
			'--older-than-days' => 7,
			'--limit' => 100,
		])
			->expectsOutputToContain('Purged 2 inbox record(s) from context <alpha> older than')
			->assertSuccessful();

		$this->assertNull($this->row($this->alpha, $oldProcessed));
		$this->assertNull($this->row($this->alpha, $oldStale));
		$this->assertNotNull($this->row($this->alpha, $recentProcessed));
		$this->assertNotNull($this->row($this->alpha, $oldIncomplete));
		$this->assertNotNull($this->row($this->beta, $betaProcessed));
	}

	#[Test]
	public function it_should_use_the_configured_retention_when_no_option_is_provided(): void
	{
		config(['inbox.retention_days' => 2]);

		$old = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-3 days'));
		$recent = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-1 day'));

		$this->artisan('manager:integration-events:inbox:purge', ['--context' => 'alpha'])
			->expectsOutputToContain('(retention: 2 day(s)).')
			->assertSuccessful();

		$this->assertNull($this->row($this->alpha, $old));
		$this->assertNotNull($this->row($this->alpha, $recent));
	}

	#[Test]
	public function it_should_fail_closed_without_a_retention_window(): void
	{
		$old = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$this->artisan('manager:integration-events:inbox:purge', ['--context' => 'alpha'])
			->expectsOutputToContain('No Inbox retention is configured.')
			->assertFailed();

		$this->assertNotNull($this->row($this->alpha, $old));
	}

	#[Test]
	public function it_should_fail_without_configured_contexts(): void
	{
		config(['inbox.contexts' => []]);

		$this->artisan('manager:integration-events:inbox:purge', ['--older-than-days' => 7])
			->expectsOutputToContain('No Inbox contexts are configured.')
			->assertFailed();
	}

	#[Test]
	public function it_should_reject_an_unknown_context_without_deleting_records(): void
	{
		$old = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'unknown',
			'--older-than-days' => 7,
		])
			->expectsOutputToContain('The Inbox context <unknown> is not configured.')
			->assertFailed();

		$this->assertNotNull($this->row($this->alpha, $old));
	}

	#[Test]
	#[DataProvider('invalidRetentionValues')]
	public function it_should_reject_an_invalid_retention_window(mixed $retention): void
	{
		$old = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'alpha',
			'--older-than-days' => $retention,
		])
			->expectsOutputToContain('retention must be a positive integer')
			->assertFailed();

		$this->assertNotNull($this->row($this->alpha, $old));
	}

	public static function invalidRetentionValues(): iterable
	{
		yield 'zero' => [0];
		yield 'negative' => [-1];
		yield 'non-numeric' => ['abc'];
		yield 'fractional' => ['7.5'];
	}

	#[Test]
	#[DataProvider('invalidLimits')]
	public function it_should_reject_an_invalid_purge_limit(mixed $limit): void
	{
		$old = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $this->now()->modify('-100 days'));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'alpha',
			'--older-than-days' => 7,
			'--limit' => $limit,
		])
			->expectsOutputToContain('The Inbox purge limit must be a positive integer.')
			->assertFailed();

		$this->assertNotNull($this->row($this->alpha, $old));
	}

	public static function invalidLimits(): iterable
	{
		yield 'zero' => [0];
		yield 'negative' => [-1];
		yield 'non-numeric' => ['abc'];
		yield 'fractional' => ['1.5'];
	}

	#[Test]
	public function it_should_purge_in_bounded_batches_across_command_invocations(): void
	{
		$now = $this->now();
		$oldest = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-10 days'));
		$middle = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-9 days'));
		$newest = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-8 days'));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'alpha',
			'--older-than-days' => 7,
			'--limit' => 1,
		])->assertSuccessful();

		$this->assertNull($this->row($this->alpha, $oldest));
		$this->assertNotNull($this->row($this->alpha, $middle));
		$this->assertNotNull($this->row($this->alpha, $newest));

		$this->artisan('manager:integration-events:inbox:purge', [
			'--context' => 'alpha',
			'--older-than-days' => 7,
			'--limit' => 1,
		])->assertSuccessful();

		$this->assertNull($this->row($this->alpha, $middle));
		$this->assertNotNull($this->row($this->alpha, $newest));
	}

	#[Test]
	public function it_should_purge_all_configured_contexts_when_no_context_is_selected(): void
	{
		$now = $this->now();
		$alphaRow = $this->seedInboxRow($this->alpha, self::ALPHA_CONSUMER, 'processed', $now->modify('-10 days'));
		$betaRow = $this->seedInboxRow($this->beta, self::BETA_CONSUMER, 'processed', $now->modify('-10 days'));

		$this->artisan('manager:integration-events:inbox:purge', ['--older-than-days' => 7])
			->expectsOutputToContain('from context <alpha>')
			->expectsOutputToContain('from context <beta>')
			->assertSuccessful();

		$this->assertNull($this->row($this->alpha, $alphaRow));
		$this->assertNull($this->row($this->beta, $betaRow));
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

		$this->connection->table($tables->inboxTable)->insert([
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
		]);

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
