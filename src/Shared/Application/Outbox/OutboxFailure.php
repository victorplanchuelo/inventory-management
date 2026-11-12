<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

use DateTimeImmutable;

final readonly class OutboxFailure
{
	public function __construct(
		public string $eventId,
		public string $eventName,
		public string $producer,
		public string $aggregateType,
		public string $aggregateId,
		public int $attempts,
		public OutboxStatus $status,
		public ?DateTimeImmutable $failedAt,
		public ?DateTimeImmutable $nextAttemptAt,
		public string $lastError,
	) {}
}
