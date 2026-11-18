<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use RuntimeException;

final readonly class MySqlInboxStore implements InboxStore
{
	private const DATE_FORMAT = 'Y-m-d H:i:s';
	private const UNIQUE_INDEX_SUFFIX = '_consumer_event_id_unique';

	public function __construct(
		private Connection $connection,
		private string $table,
	) {}

	public function hasHandled(string $consumer, string $eventId): bool
	{
		return $this->connection->table($this->table)
			->where('consumer', $consumer)
			->where('event_id', $eventId)
			->whereNotNull('processed_at')
			->whereNotNull('outcome')
			->exists();
	}

	public function claim(IntegrationEventEnvelope $event, string $consumer, DateTimeImmutable $createdAt): bool
	{
		try {
			$this->connection->table($this->table)->insert([
				'id' => SimpleUuid::random()->value(),
				'consumer' => $consumer,
				'event_id' => $event->eventId,
				'event_name' => $event->eventName,
				'event_version' => $event->eventVersion,
				'aggregate_type' => $event->aggregateType,
				'aggregate_id' => $event->aggregateId,
				'aggregate_version' => $event->aggregateVersion,
				'occurred_at' => $this->formatDate($event->occurredAt),
				'processed_at' => null,
				'outcome' => null,
				'correlation_id' => $event->correlationId,
				'causation_id' => $event->causationId,
				'created_at' => $this->formatDate($createdAt),
			]);
		} catch (QueryException $error) {
			if ($this->isConsumerEventIdDuplicate($error)) {
				return false;
			}

			throw $error;
		}

		return true;
	}

	public function markProcessed(
		string $consumer,
		string $eventId,
		InboxOutcome $outcome,
		DateTimeImmutable $processedAt
	): void {
		$affected = $this->connection->table($this->table)
			->where('consumer', $consumer)
			->where('event_id', $eventId)
			->update([
				'processed_at' => $this->formatDate($processedAt),
				'outcome' => $outcome->value,
			]);

		if ($affected !== 1) {
			throw new RuntimeException(
				sprintf('The inbox claim for consumer <%s> and event <%s> could not be marked as processed.', $consumer, $eventId)
			);
		}
	}

	public function find(string $consumer, string $eventId): ?InboxRecord
	{
		$row = $this->connection->table($this->table)
			->where('consumer', $consumer)
			->where('event_id', $eventId)
			->first();

		return $row === null ? null : InboxRecord::fromDatabaseRow((array) $row);
	}

	public function lastProcessedAggregateVersion(
		string $consumer,
		string $aggregateType,
		string $aggregateId
	): ?int {
		$version = $this->connection->table($this->table)
			->where('consumer', $consumer)
			->where('aggregate_type', $aggregateType)
			->where('aggregate_id', $aggregateId)
			->where('outcome', InboxOutcome::PROCESSED->value)
			->max('aggregate_version');

		return is_numeric($version) ? (int) $version : null;
	}

	private function isConsumerEventIdDuplicate(QueryException $error): bool
	{
		if ((string) $error->getCode() !== '23000' && (int) ($error->errorInfo[1] ?? 0) !== 1062) {
			return false;
		}

		$message = $error->getMessage();

		return str_contains($message, $this->table . self::UNIQUE_INDEX_SUFFIX)
			|| str_contains($message, 'consumer_event_id_unique');
	}

	private function formatDate(DateTimeImmutable $date): string
	{
		return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
	}
}
