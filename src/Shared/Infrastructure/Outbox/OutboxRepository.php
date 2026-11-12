<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use Manager\Shared\Application\Outbox\OutboxStatusSnapshot;

interface OutboxRepository
{
	/** @return list<OutboxRecord> */
	public function claim(int $limit, DateTimeImmutable $now, string $publisherId): array;

	public function status(DateTimeImmutable $now, int $recentFailureLimit = 10): OutboxStatusSnapshot;

	public function purgePublishedBefore(DateTimeImmutable $cutoff): int;

	public function markPublished(string $recordId, string $claimToken, DateTimeImmutable $publishedAt): bool;

	public function scheduleRetry(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $nextAttemptAt,
		string $lastError,
	): bool;

	public function markFailed(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $failedAt,
		string $lastError,
	): bool;

	public function requeueFailed(string $eventId): bool;
}
