<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;

interface OutboxRepository
{
	/** @return list<OutboxRecord> */
	public function claim(int $limit, DateTimeImmutable $now, string $publisherId): array;

	public function markPublished(string $recordId, string $claimToken, DateTimeImmutable $publishedAt): bool;
}
