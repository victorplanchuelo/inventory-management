<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OutboxStatusSnapshot
{
	/**
	 * @param list<OutboxFailure> $recentFailures
	 */
	public function __construct(
		public int $pending,
		public int $claimed,
		public int $published,
		public int $failed,
		public int $retrying,
		public ?DateTimeImmutable $oldestPendingCreatedAt,
		public int $staleClaims,
		public int $activePublishers,
		public ?DateTimeImmutable $lastPublishedAt,
		public array $recentFailures,
	) {
		foreach ([
			'pending' => $this->pending,
			'claimed' => $this->claimed,
			'published' => $this->published,
			'failed' => $this->failed,
			'retrying' => $this->retrying,
			'staleClaims' => $this->staleClaims,
			'activePublishers' => $this->activePublishers,
		] as $name => $count) {
			if ($count < 0) {
				throw new InvalidArgumentException(sprintf('The Outbox status count <%s> cannot be negative.', $name));
			}
		}
	}

	public function oldestPendingAgeSeconds(DateTimeImmutable $now): ?int
	{
		if ($this->oldestPendingCreatedAt === null) {
			return null;
		}

		$nowUtc = $now->setTimezone(new DateTimeZone('UTC'));

		return max(0, $nowUtc->getTimestamp() - $this->oldestPendingCreatedAt->getTimestamp());
	}
}
