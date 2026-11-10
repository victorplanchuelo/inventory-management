<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeZone;
use JsonException;

final readonly class OutboxEnvelopeSerializer
{
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
	private const OCCURRED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

	public function serialize(OutboxRecord $record): string
	{
		$properties = [
			'"eventId":' . $this->encodeString($record->eventId),
			'"eventName":' . $this->encodeString($record->eventName),
			'"eventVersion":' . $this->encodeString($record->eventVersion),
			'"aggregateType":' . $this->encodeString($record->aggregateType),
			'"aggregateId":' . $this->encodeString($record->aggregateId),
		];

		if ($record->aggregateVersion !== null) {
			$properties[] = '"aggregateVersion":' . $record->aggregateVersion;
		}

		$properties[] = '"occurredAt":' . $this->encodeString(
			$record->occurredAt->setTimezone(new DateTimeZone('UTC'))->format(self::OCCURRED_AT_FORMAT)
		);

		// The persisted payload text is inserted verbatim. It must never be decoded and re-encoded.
		$properties[] = '"payload":' . $record->payload;

		if ($record->correlationId !== null) {
			$properties[] = '"correlationId":' . $this->encodeString($record->correlationId);
		}

		if ($record->causationId !== null) {
			$properties[] = '"causationId":' . $this->encodeString($record->causationId);
		}

		return '{' . implode(',', $properties) . '}';
	}

	private function encodeString(string $value): string
	{
		try {
			return json_encode($value, self::JSON_FLAGS);
		} catch (JsonException $exception) {
			throw new OutboxSerializationException('The integration-event envelope could not be serialized.', 0, $exception);
		}
	}
}
