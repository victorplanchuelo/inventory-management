<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class OutboxRetryPolicy
{
	public const MAX_ATTEMPTS = 10;

	private const BASE_BACKOFF_SECONDS = 5;
	private const BACKOFF_FACTOR = 2;
	private const MAX_BACKOFF_SECONDS = 600;

	public function isTerminal(int $attempts): bool
	{
		$this->assertPositiveAttempts($attempts);

		return $attempts >= self::MAX_ATTEMPTS;
	}

	public function nextAttemptAt(DateTimeImmutable $failedAt, int $attempts): DateTimeImmutable
	{
		$this->assertPositiveAttempts($attempts);

		if ($this->isTerminal($attempts)) {
			throw new InvalidArgumentException('The Outbox retry policy cannot schedule an eleventh publication attempt.');
		}

		$delay = $this->backoffSeconds($attempts);

		return $failedAt
			->setTimezone(new DateTimeZone('UTC'))
			->add(new DateInterval(sprintf('PT%dS', $delay)));
	}

	private function backoffSeconds(int $attempts): int
	{
		$delay = self::BASE_BACKOFF_SECONDS;
		$remainingDoublings = $attempts - 1;

		while ($remainingDoublings > 0 && $delay < self::MAX_BACKOFF_SECONDS) {
			$delay = min($delay * self::BACKOFF_FACTOR, self::MAX_BACKOFF_SECONDS);
			$remainingDoublings--;
		}

		return $delay;
	}

	private function assertPositiveAttempts(int $attempts): void
	{
		if ($attempts < 1) {
			throw new InvalidArgumentException('The Outbox attempt count must be a positive integer.');
		}
	}
}
