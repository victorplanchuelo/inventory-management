<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Manager\Shared\Application\Inbox\InboxMaintenanceStore;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxStatusSnapshot;

final readonly class MySqlInboxMaintenance implements InboxMaintenanceStore
{
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * @param array<string, string> $tables
	 */
	public function __construct(
		private Connection $connection,
		private array $tables,
	) {}

	public function contexts(): array
	{
		return array_keys($this->tables);
	}

	public function purgeProcessedBefore(string $context, DateTimeImmutable $cutoff, int $limit): int
	{
		if ($limit < 1) {
			throw new InvalidArgumentException('The Inbox purge limit must be a positive integer.');
		}

		return (int) $this->connection->table($this->tableFor($context))
			->whereNotNull('processed_at')
			->whereNotNull('outcome')
			->where('processed_at', '<', $this->formatDate($cutoff))
			->orderBy('processed_at')
			->orderBy('id')
			->limit($limit)
			->delete();
	}

	public function snapshot(string $context, ?DateTimeImmutable $purgeCutoff): InboxStatusSnapshot
	{
		$table = $this->tableFor($context);
		$wrappedTable = $this->connection->getQueryGrammar()->wrapTable($table);

		$counts = (array) $this->connection->selectOne(
			sprintf(
				'SELECT
					COUNT(*) AS total_records,
					COALESCE(SUM(outcome = ?), 0) AS processed_records,
					COALESCE(SUM(outcome = ?), 0) AS stale_records,
					MIN(processed_at) AS oldest_processed_at,
					MAX(processed_at) AS newest_processed_at
				FROM %s',
				$wrappedTable
			),
			[InboxOutcome::PROCESSED->value, InboxOutcome::STALE->value]
		);

		$consumerRows = $this->connection->table($table)
			->selectRaw('consumer, COUNT(*) AS aggregate')
			->groupBy('consumer')
			->orderBy('consumer')
			->get();

		$recordsByConsumer = [];

		foreach ($consumerRows as $row) {
			$values = (array) $row;
			$recordsByConsumer[(string) $values['consumer']] = (int) $values['aggregate'];
		}

		$purgeableRecords = $purgeCutoff === null
			? null
			: (int) $this->connection->table($table)
				->whereNotNull('processed_at')
				->whereNotNull('outcome')
				->where('processed_at', '<', $this->formatDate($purgeCutoff))
				->count();

		return new InboxStatusSnapshot(
			context: $context,
			totalRecords: (int) ($counts['total_records'] ?? 0),
			processedRecords: (int) ($counts['processed_records'] ?? 0),
			staleRecords: (int) ($counts['stale_records'] ?? 0),
			recordsByConsumer: $recordsByConsumer,
			oldestProcessedAt: $this->optionalDate($counts['oldest_processed_at'] ?? null),
			newestProcessedAt: $this->optionalDate($counts['newest_processed_at'] ?? null),
			purgeableRecords: $purgeableRecords,
		);
	}

	private function tableFor(string $context): string
	{
		$table = $this->tables[$context] ?? null;

		if ($table === null || trim($table) === '') {
			throw new InvalidArgumentException(sprintf('The Inbox context <%s> is not configured.', $context));
		}

		return $table;
	}

	private function optionalDate(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}

	private function formatDate(DateTimeImmutable $date): string
	{
		return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
	}
}
