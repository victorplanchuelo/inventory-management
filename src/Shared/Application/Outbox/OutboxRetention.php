<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OutboxRetention
{
	public const DEFAULT_PUBLISHED_RETENTION_DAYS = 7;

	private function __construct(public int $publishedRetentionDays) {}

	public static function resolve(mixed $commandLineDays, mixed $configuredDays): self
	{
		$value = $commandLineDays ?? $configuredDays ?? self::DEFAULT_PUBLISHED_RETENTION_DAYS;

		if (is_string($value)) {
			$value = trim($value);
		}

		if (!is_numeric($value)) {
			throw new InvalidArgumentException('The Outbox published retention must be a positive integer number of days.');
		}

		$numericValue = (float) $value;

		if (floor($numericValue) !== $numericValue) {
			throw new InvalidArgumentException('The Outbox published retention must be a positive integer number of days.');
		}

		$days = (int) $numericValue;

		if ($days < 1) {
			throw new InvalidArgumentException('The Outbox published retention must be a positive integer number of days.');
		}

		return new self($days);
	}

	public function cutoff(DateTimeImmutable $now): DateTimeImmutable
	{
		return $now
			->setTimezone(new DateTimeZone('UTC'))
			->modify(sprintf('-%d days', $this->publishedRetentionDays));
	}
}
