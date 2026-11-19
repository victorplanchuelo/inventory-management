<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\IntegrationEvent;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Manager\Shared\Application\Inbox\Exception\InvalidInboxRetention;
use Manager\Shared\Application\Inbox\InboxMaintenanceStore;
use Manager\Shared\Application\Inbox\InboxRetention;
use Symfony\Component\Console\Output\OutputInterface;

final class PurgeInboxCommand extends Command
{
	private const DEFAULT_LIMIT = 1000;

	protected $signature = 'manager:integration-events:inbox:purge
		{--context= : Inbox context to purge}
		{--older-than-days= : Retention window in days for processed records}
		{--limit=1000 : Maximum number of records to delete per context}';

	protected $description = 'Purge committed Inbox records older than the configured retention window';

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

		if ($retention === null) {
			$this->error('No Inbox retention is configured. Provide --older-than-days or set INBOX_RETENTION_DAYS.');

			return self::FAILURE;
		}

		$limit = $this->positiveIntegerOption('limit');

		if ($limit === null) {
			$this->error('The Inbox purge limit must be a positive integer.');

			return self::FAILURE;
		}

		$contexts = $this->selectedContexts();

		if ($contexts === null) {
			return self::FAILURE;
		}

		if ($contexts === []) {
			$this->error('No Inbox contexts are configured.');

			return self::FAILURE;
		}

		$cutoff = $retention->cutoff(new DateTimeImmutable('now', new DateTimeZone('UTC')));

		foreach ($contexts as $context) {
			$purged = $this->maintenance->purgeProcessedBefore($context, $cutoff, $limit);

			$this->getOutput()->writeln(
				sprintf(
					'Purged %d inbox record(s) from context <%s> older than %s UTC (retention: %d day(s)).',
					$purged,
					$context,
					$cutoff->format('Y-m-d H:i:s'),
					$retention->retentionDays,
				),
				OutputInterface::OUTPUT_RAW
			);
		}

		return self::SUCCESS;
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

	private function positiveIntegerOption(string $name): ?int
	{
		$value = $this->option($name);

		if ($value === null || $value === '') {
			return self::DEFAULT_LIMIT;
		}

		if (is_string($value)) {
			$value = trim($value);
		}

		if (!is_numeric($value)) {
			return null;
		}

		$numericValue = (float) $value;

		if (floor($numericValue) !== $numericValue || $numericValue < 1) {
			return null;
		}

		return (int) $numericValue;
	}
}
