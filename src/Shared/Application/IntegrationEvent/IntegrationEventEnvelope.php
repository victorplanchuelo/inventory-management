<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class IntegrationEventEnvelope
{
	private const MAX_EVENT_NAME_LENGTH = 255;
	private const MAX_EVENT_VERSION_LENGTH = 32;
	private const MAX_AGGREGATE_TYPE_LENGTH = 255;
	private const MAX_AGGREGATE_ID_LENGTH = 255;
	private const MAX_CORRELATION_ID_LENGTH = 255;
	private const MAX_CAUSATION_ID_LENGTH = 255;
	private const OCCURRED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * @param array<string, mixed> $payload
	 */
	private function __construct(
		public string $eventId,
		public string $eventName,
		public string $eventVersion,
		public string $aggregateType,
		public string $aggregateId,
		public ?int $aggregateVersion,
		public DateTimeImmutable $occurredAt,
		public array $payload,
		public ?string $correlationId,
		public ?string $causationId,
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$eventId = self::requiredString($data, 'eventId');
		self::ensureIsUuid($eventId);

		return new self(
			$eventId,
			self::requiredString($data, 'eventName', self::MAX_EVENT_NAME_LENGTH),
			self::requiredString($data, 'eventVersion', self::MAX_EVENT_VERSION_LENGTH),
			self::requiredString($data, 'aggregateType', self::MAX_AGGREGATE_TYPE_LENGTH),
			self::requiredString($data, 'aggregateId', self::MAX_AGGREGATE_ID_LENGTH),
			self::optionalPositiveInteger($data, 'aggregateVersion'),
			self::requiredOccurredAt($data),
			self::requiredPayload($data),
			self::optionalString($data, 'correlationId', self::MAX_CORRELATION_ID_LENGTH),
			self::optionalString($data, 'causationId', self::MAX_CAUSATION_ID_LENGTH),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function requiredString(array $data, string $field, int $maxLength = 255): string
	{
		if (!array_key_exists($field, $data)) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsMissing($field);
		}

		$value = $data[$field];

		if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsInvalid($field);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function optionalString(array $data, string $field, int $maxLength): ?string
	{
		if (!array_key_exists($field, $data) || $data[$field] === null) {
			return null;
		}

		return self::requiredString($data, $field, $maxLength);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function optionalPositiveInteger(array $data, string $field): ?int
	{
		if (!array_key_exists($field, $data) || $data[$field] === null) {
			return null;
		}

		$value = $data[$field];

		if (!is_int($value) || $value < 1) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsInvalid($field);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return array<string, mixed>
	 */
	private static function requiredPayload(array $data): array
	{
		if (!array_key_exists('payload', $data) || !is_array($data['payload'])) {
			throw InvalidIntegrationEventEnvelope::becausePayloadIsInvalid();
		}

		return $data['payload'];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function requiredOccurredAt(array $data): DateTimeImmutable
	{
		if (!array_key_exists('occurredAt', $data)) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsMissing('occurredAt');
		}

		$value = $data['occurredAt'];

		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsInvalid('occurredAt');
		}

		$occurredAt = DateTimeImmutable::createFromFormat(self::OCCURRED_AT_FORMAT, $value, new DateTimeZone('UTC'));

		if ($occurredAt === false || $occurredAt->format(self::OCCURRED_AT_FORMAT) !== $value) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsInvalid('occurredAt');
		}

		return $occurredAt->setTimezone(new DateTimeZone('UTC'));
	}

	private static function ensureIsUuid(string $eventId): void
	{
		try {
			new SimpleUuid($eventId);
		} catch (InvalidArgumentException) {
			throw InvalidIntegrationEventEnvelope::becauseFieldIsInvalid('eventId');
		}
	}
}
