<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\IntegrationEvent;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Manager\Shared\Application\Inbox\Exception\InvalidInboxRetention;
use Manager\Shared\Application\Inbox\InboxMaintenanceStore;
use Manager\Shared\Application\Inbox\InboxRetention;
use Manager\Shared\Application\Inbox\InboxStatusSnapshot;

final class InboxStatusCommand extends Command
{
	protected $signature = 'manager:integration-events:inbox:status
		{--context= : Inbox context to report}
		{--older-than-days= : Retention window in days used to calculate purgeable records}';

	protected $description = 'Report the operational status of the per-context Inbox tables';

	public function __construct(private readonly InboxMaintenanceStore $maintenance)
	{
		parent::__construct();
	}

	public function handle(): int
	{
		try {
			$retention = InboxRetention::resolve($this->option('older-than-days'), config('inbox.retention_days'));
		} catch (InvalidInboxRetention $exception) {
			$this->error($exception->getMessage());

			return self::FAILURE;
		}

		$contexts = $this->selectedContexts();

		if ($contexts === null) {
			return self::FAILURE;
		}

		$this->info('Inbox status');
		$this->line(sprintf('Database connection: %s', (string) config('database.default', 'default')));
		$this->line(sprintf(
			'Retention: %s',
			$retention === null ? 'not configured' : sprintf('%d day(s)', $retention->retentionDays)
		));
		$this->newLine();

		if ($contexts === []) {
			$this->line('No Inbox contexts are configured.');

			return self::SUCCESS;
		}

		$cutoff = $retention?->cutoff(new DateTimeImmutable('now', new DateTimeZone('UTC')));

		foreach ($contexts as $context) {
			$this->renderContext($this->maintenance->snapshot($context, $cutoff));
		}

		return self::SUCCESS;
	}

	private function renderContext(InboxStatusSnapshot $snapshot): void
	{
		$this->line(sprintf('Context: %s', $snapshot->context));
		$this->line(sprintf('Total records: %d', $snapshot->totalRecords));
		$this->line(sprintf('Processed records: %d', $snapshot->processedRecords));
		$this->line(sprintf('Stale records: %d', $snapshot->staleRecords));
		$this->line('Records by consumer:');

		if ($snapshot->recordsByConsumer === []) {
			$this->line('  none');
		} else {
			foreach ($snapshot->recordsByConsumer as $consumer => $count) {
				$this->line(sprintf('  %s: %d', $consumer, $count));
			}
		}

		$this->line(sprintf('Oldest processed at: %s', $this->formatDate($snapshot->oldestProcessedAt)));
		$this->line(sprintf('Newest processed at: %s', $this->formatDate($snapshot->newestProcessedAt)));
		$this->line(sprintf(
			'Purgeable records: %s',
			$snapshot->purgeableRecords === null
				? 'not evaluated (no retention configured)'
				: (string) $snapshot->purgeableRecords
		));
		$this->newLine();
	}

	/**
	 * @return list<string>|null
	 */
	private function selectedContexts(): ?array
	{
		$available = $this->maintenance->contexts();
		$requested = $this->option('context');

		if ($requested === null || $requested === '') {
			return $available;
		}

		if (!is_string($requested) || !in_array($requested, $available, true)) {
			$this->error(sprintf('The Inbox context <%s> is not configured.', is_scalar($requested) ? (string) $requested : ''));

			return null;
		}

		return [$requested];
	}

	private function formatDate(?DateTimeImmutable $date): string
	{
		if ($date === null) {
			return 'never';
		}

		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
	}
}
