<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Throwable;

final readonly class OutboxRecord
{
	public function __construct(
		public string $id,
		public string $producer,
		public string $eventId,
		public string $eventName,
		public string $eventVersion,
		public string $aggregateType,
		public string $aggregateId,
		public ?int $aggregateVersion,
		public ?string $correlationId,
		public ?string $causationId,
		public string $payload,
		public DateTimeImmutable $occurredAt,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $publishedAt,
		public int $attempts,
		public ?DateTimeImmutable $nextAttemptAt,
		public ?DateTimeImmutable $lockedAt,
		public ?string $claimToken,
		public ?string $publisherId,
		public ?string $lastError,
		public OutboxStatus $status,
		public ?DateTimeImmutable $failedAt,
	) {}

	/** @param array<string, mixed> $row */
	public static function fromDatabaseRow(array $row): self
	{
		return new self(
			self::stringValue($row, 'id'),
			self::stringValue($row, 'producer'),
			self::stringValue($row, 'event_id'),
			self::stringValue($row, 'event_name'),
			self::stringValue($row, 'event_version'),
			self::stringValue($row, 'aggregate_type'),
			self::stringValue($row, 'aggregate_id'),
			self::optionalIntValue($row, 'aggregate_version'),
			self::optionalStringValue($row, 'correlation_id'),
			self::optionalStringValue($row, 'causation_id'),
			self::stringValue($row, 'payload'),
			self::dateTimeValue($row, 'occurred_at'),
			self::dateTimeValue($row, 'created_at'),
			self::optionalDateTimeValue($row, 'published_at'),
			self::intValue($row, 'attempts'),
			self::optionalDateTimeValue($row, 'next_attempt_at'),
			self::optionalDateTimeValue($row, 'locked_at'),
			self::optionalStringValue($row, 'claim_token'),
			self::optionalStringValue($row, 'publisher_id'),
			self::optionalStringValue($row, 'last_error'),
			OutboxStatus::from(self::stringValue($row, 'status')),
			self::optionalDateTimeValue($row, 'failed_at'),
		);
	}

	/** @param array<string, mixed> $row */
	private static function stringValue(array $row, string $key): string
	{
		if (!array_key_exists($key, $row) || !is_scalar($row[$key])) {
			throw new InvalidArgumentException(sprintf('Outbox row <%s> must be a string.', $key));
		}

		return (string) $row[$key];
	}

	/** @param array<string, mixed> $row */
	private static function intValue(array $row, string $key): int
	{
		if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
			throw new InvalidArgumentException(sprintf('Outbox row <%s> must be an integer.', $key));
		}

		return (int) $row[$key];
	}

	/** @param array<string, mixed> $row */
	private static function optionalStringValue(array $row, string $key): ?string
	{
		if (!array_key_exists($key, $row) || $row[$key] === null) {
			return null;
		}

		return self::stringValue($row, $key);
	}

	/** @param array<string, mixed> $row */
	private static function optionalIntValue(array $row, string $key): ?int
	{
		if (!array_key_exists($key, $row) || $row[$key] === null) {
			return null;
		}

		return self::intValue($row, $key);
	}

	/** @param array<string, mixed> $row */
	private static function dateTimeValue(array $row, string $key): DateTimeImmutable
	{
		return self::parseDateTime(self::stringValue($row, $key), $key);
	}

	/** @param array<string, mixed> $row */
	private static function optionalDateTimeValue(array $row, string $key): ?DateTimeImmutable
	{
		if (!array_key_exists($key, $row) || $row[$key] === null) {
			return null;
		}

		return self::parseDateTime(self::stringValue($row, $key), $key);
	}

	private static function parseDateTime(string $value, string $key): DateTimeImmutable
	{
		try {
			return new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (Throwable) {
			throw new InvalidArgumentException(sprintf('Outbox row <%s> must contain a valid date-time.', $key));
		}
	}
}
