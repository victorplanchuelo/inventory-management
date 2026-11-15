<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class InboxRecord
{
	public function __construct(
		public string $id,
		public string $consumer,
		public string $eventId,
		public string $eventName,
		public string $eventVersion,
		public string $aggregateType,
		public string $aggregateId,
		public ?int $aggregateVersion,
		public DateTimeImmutable $occurredAt,
		public ?DateTimeImmutable $processedAt,
		public ?InboxOutcome $outcome,
		public ?string $correlationId,
		public ?string $causationId,
		public DateTimeImmutable $createdAt,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromDatabaseRow(array $row): self
	{
		$outcome = self::optionalStringValue($row, 'outcome');

		return new self(
			self::stringValue($row, 'id'),
			self::stringValue($row, 'consumer'),
			self::stringValue($row, 'event_id'),
			self::stringValue($row, 'event_name'),
			self::stringValue($row, 'event_version'),
			self::stringValue($row, 'aggregate_type'),
			self::stringValue($row, 'aggregate_id'),
			self::optionalIntValue($row, 'aggregate_version'),
			self::dateTimeValue($row, 'occurred_at'),
			self::optionalDateTimeValue($row, 'processed_at'),
			$outcome === null ? null : InboxOutcome::from($outcome),
			self::optionalStringValue($row, 'correlation_id'),
			self::optionalStringValue($row, 'causation_id'),
			self::dateTimeValue($row, 'created_at'),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function stringValue(array $row, string $key): string
	{
		if (!array_key_exists($key, $row) || !is_scalar($row[$key])) {
			throw new InvalidArgumentException(sprintf('Inbox row <%s> must be a string.', $key));
		}

		return (string) $row[$key];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function intValue(array $row, string $key): int
	{
		if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
			throw new InvalidArgumentException(sprintf('Inbox row <%s> must be an integer.', $key));
		}

		return (int) $row[$key];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function optionalStringValue(array $row, string $key): ?string
	{
		if (!array_key_exists($key, $row) || $row[$key] === null) {
			return null;
		}

		return self::stringValue($row, $key);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function optionalIntValue(array $row, string $key): ?int
	{
		if (!array_key_exists($key, $row) || $row[$key] === null) {
			return null;
		}

		return self::intValue($row, $key);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function dateTimeValue(array $row, string $key): DateTimeImmutable
	{
		return self::parseDateTime(self::stringValue($row, $key), $key);
	}

	/**
	 * @param array<string, mixed> $row
	 */
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
			throw new InvalidArgumentException(sprintf('Inbox row <%s> must contain a valid date-time.', $key));
		}
	}
}
