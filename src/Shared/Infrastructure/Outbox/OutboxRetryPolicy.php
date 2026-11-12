<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OutboxRetryPolicy
{
	public const MAX_ATTEMPTS = 10;

	private const BASE_BACKOFF_SECONDS = 5;
	private const BACKOFF_FACTOR = 2;
	private const MAX_BACKOFF_SECONDS = 600;

	public function __construct(
		private int $maxAttempts = self::MAX_ATTEMPTS,
		private int $baseBackoffSeconds = self::BASE_BACKOFF_SECONDS,
		private int $backoffFactor = self::BACKOFF_FACTOR,
		private int $maxBackoffSeconds = self::MAX_BACKOFF_SECONDS,
	) {
		if ($this->maxAttempts < 1) {
			throw new InvalidArgumentException('The Outbox attempt limit must be a positive integer.');
		}

		if ($this->baseBackoffSeconds < 1) {
			throw new InvalidArgumentException('The Outbox base backoff must be a positive number of seconds.');
		}

		if ($this->backoffFactor < 1) {
			throw new InvalidArgumentException('The Outbox backoff factor must be a positive integer.');
		}

		if ($this->maxBackoffSeconds < $this->baseBackoffSeconds) {
			throw new InvalidArgumentException('The Outbox maximum backoff cannot be shorter than the base backoff.');
		}
	}

	public function isTerminal(int $attempts): bool
	{
		$this->assertPositiveAttempts($attempts);

		return $attempts >= $this->maxAttempts;
	}

	public function nextAttemptAt(DateTimeImmutable $failedAt, int $attempts): DateTimeImmutable
	{
		$this->assertPositiveAttempts($attempts);

		if ($this->isTerminal($attempts)) {
			throw new InvalidArgumentException(
				sprintf('The Outbox retry policy cannot schedule publication attempt %d of %d.', $attempts + 1, $this->maxAttempts)
			);
		}

		$delay = $this->backoffSeconds($attempts);

		return $failedAt
			->setTimezone(new DateTimeZone('UTC'))
			->add(new DateInterval(sprintf('PT%dS', $delay)));
	}

	private function backoffSeconds(int $attempts): int
	{
		$delay = $this->baseBackoffSeconds;
		$remainingDoublings = $attempts - 1;

		while ($remainingDoublings > 0 && $delay < $this->maxBackoffSeconds) {
			$delay = min($delay * $this->backoffFactor, $this->maxBackoffSeconds);
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
