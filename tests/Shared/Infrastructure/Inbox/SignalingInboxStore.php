<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Inbox;

use DateTimeImmutable;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

/** @param resource $signal */
final readonly class SignalingInboxStore implements InboxStore
{
	/**
	 * @param resource $signal
	 */
	public function __construct(
		private InboxStore $store,
		private mixed $signal,
	) {}

	public function hasHandled(string $consumer, string $eventId): bool
	{
		return $this->store->hasHandled($consumer, $eventId);
	}

	public function claim(IntegrationEventEnvelope $event, string $consumer, DateTimeImmutable $createdAt): bool
	{
		fwrite($this->signal, 'claiming');
		fflush($this->signal);

		return $this->store->claim($event, $consumer, $createdAt);
	}

	public function markProcessed(
		string $consumer,
		string $eventId,
		InboxOutcome $outcome,
		DateTimeImmutable $processedAt
	): void {
		$this->store->markProcessed($consumer, $eventId, $outcome, $processedAt);
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
}
