<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\Exception\InvalidInboxRetention;

final readonly class InboxRetention
{
	private function __construct(public int $retentionDays) {}

	public static function resolve(mixed $commandLineDays, mixed $configuredDays): ?self
	{
		$value = self::definedValue($commandLineDays) ?? self::definedValue($configuredDays);

		if ($value === null) {
			return null;
		}

		if (is_string($value)) {
			$value = trim($value);
		}

		if (!is_numeric($value)) {
			throw InvalidInboxRetention::becauseItIsNotAPositiveInteger($value);
		}

		$numericValue = (float) $value;

		if (floor($numericValue) !== $numericValue) {
			throw InvalidInboxRetention::becauseItIsNotAPositiveInteger($value);
		}

		$days = (int) $numericValue;

		if ($days < 1) {
			throw InvalidInboxRetention::becauseItIsNotAPositiveInteger($value);
		}

		return new self($days);
	}

	public function cutoff(DateTimeImmutable $now): DateTimeImmutable
	{
		return $now
			->setTimezone(new DateTimeZone('UTC'))
			->modify(sprintf('-%d days', $this->retentionDays));
	}

	private static function definedValue(mixed $value): mixed
	{
		if ($value === null) {
			return null;
		}

		if (is_string($value) && trim($value) === '') {
			return null;
		}

		return $value;
	}
}
