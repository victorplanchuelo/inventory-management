<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

interface InboxStore
{
	public function hasHandled(string $consumer, string $eventId): bool;

	public function claim(IntegrationEventEnvelope $event, string $consumer, DateTimeImmutable $createdAt): bool;

	public function markProcessed(
		string $consumer,
		string $eventId,
		InboxOutcome $outcome,
		DateTimeImmutable $processedAt
	): void;

	public function find(string $consumer, string $eventId): ?InboxRecord;
}
