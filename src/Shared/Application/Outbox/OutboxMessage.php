<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class OutboxMessage
{
	private const MAX_EVENT_NAME_LENGTH = 255;
	private const MAX_VERSION_LENGTH = 32;
	private const MAX_PRODUCER_LENGTH = 64;
	private const MAX_AGGREGATE_TYPE_LENGTH = 255;
	private const MAX_CORRELATION_ID_LENGTH = 255;
	private const MAX_CAUSATION_ID_LENGTH = 255;

	public function __construct(
		private string $eventId,
		private string $eventName,
		private string $eventVersion,
		private string $producer,
		private string $aggregateType,
		private string $aggregateId,
		private string $payloadJson,
		private DateTimeImmutable $occurredOn,
		private ?int $aggregateVersion = null,
		private ?string $correlationId = null,
		private ?string $causationId = null,
	) {
		$this->ensureIsUuid('eventId', $eventId);
		$this->ensureIsUuid('aggregateId', $aggregateId);
		$this->ensureIsNotEmpty('eventName', $eventName);
		$this->ensureHasValidLength('eventName', $eventName, self::MAX_EVENT_NAME_LENGTH);
		$this->ensureIsNotEmpty('eventVersion', $eventVersion);
		$this->ensureHasValidLength('eventVersion', $eventVersion, self::MAX_VERSION_LENGTH);
		$this->ensureIsNotEmpty('producer', $producer);
		$this->ensureHasValidLength('producer', $producer, self::MAX_PRODUCER_LENGTH);
		$this->ensureIsNotEmpty('aggregateType', $aggregateType);
		$this->ensureHasValidLength('aggregateType', $aggregateType, self::MAX_AGGREGATE_TYPE_LENGTH);
		$this->ensureIsValidJson($payloadJson);
		$this->ensureIsPositiveWhenPresent('aggregateVersion', $aggregateVersion);
		$this->ensureOptionalStringIsValid('correlationId', $correlationId, self::MAX_CORRELATION_ID_LENGTH);
		$this->ensureOptionalStringIsValid('causationId', $causationId, self::MAX_CAUSATION_ID_LENGTH);
	}

	public function eventId(): string
	{
		return $this->eventId;
	}

	public function eventName(): string
	{
		return $this->eventName;
	}

	public function eventVersion(): string
	{
		return $this->eventVersion;
	}

	public function producer(): string
	{
		return $this->producer;
	}

	public function aggregateType(): string
	{
		return $this->aggregateType;
	}

	public function aggregateId(): string
	{
		return $this->aggregateId;
	}

	public function payloadJson(): string
	{
		return $this->payloadJson;
	}

	public function occurredOn(): DateTimeImmutable
	{
		return $this->occurredOn;
	}

	public function aggregateVersion(): ?int
	{
		return $this->aggregateVersion;
	}

	public function correlationId(): ?string
	{
		return $this->correlationId;
	}

	public function causationId(): ?string
	{
		return $this->causationId;
	}

	private function ensureIsUuid(string $field, string $value): void
	{
		try {
			new SimpleUuid($value);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException(sprintf('Outbox message <%s> must be a valid UUID, <%s> given.', $field, $value));
		}
	}

	private function ensureIsNotEmpty(string $field, string $value): void
	{
		if (trim($value) === '') {
			throw new InvalidArgumentException(sprintf('Outbox message <%s> must not be empty.', $field));
		}
	}

	private function ensureHasValidLength(string $field, string $value, int $maxLength): void
	{
		if (mb_strlen($value, 'UTF-8') > $maxLength) {
			throw new InvalidArgumentException(
				sprintf('Outbox message <%s> must not be longer than <%d> characters.', $field, $maxLength)
			);
		}
	}

	private function ensureIsValidJson(string $payloadJson): void
	{
		try {
			json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new InvalidArgumentException('Outbox message payload must be valid JSON.');
		}
	}

	private function ensureIsPositiveWhenPresent(string $field, ?int $value): void
	{
		if ($value !== null && $value < 1) {
			throw new InvalidArgumentException(sprintf('Outbox message <%s> must be a positive integer when present.', $field));
		}
	}

	private function ensureOptionalStringIsValid(string $field, ?string $value, int $maxLength): void
	{
		if ($value === null) {
			return;
		}

		$this->ensureIsNotEmpty($field, $value);
		$this->ensureHasValidLength($field, $value, $maxLength);
	}
}
