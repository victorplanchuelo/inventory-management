<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;

interface InboxMaintenanceStore
{
	/** @return list<string> */
	public function contexts(): array;

	public function purgeProcessedBefore(string $context, DateTimeImmutable $cutoff, int $limit): int;

	public function snapshot(string $context, ?DateTimeImmutable $purgeCutoff): InboxStatusSnapshot;
}
