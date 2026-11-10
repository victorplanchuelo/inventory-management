<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use Manager\Shared\Infrastructure\Outbox\OutboxSerializationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxEnvelopeSerializerTest extends UnitTestCase
{
	private const EVENT_ID = '5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01';
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	private OutboxEnvelopeSerializer $serializer;

	protected function setUp(): void
	{
		parent::setUp();

		$this->serializer = new OutboxEnvelopeSerializer();
	}

	#[Test]
	public function it_should_build_the_envelope_in_the_fixed_property_order(): void
	{
		$payload = '{ "decimal" : 1.50, "unicode" : "Málaga 🐢", "nested" : { "b" : 2, "a" : 1 } }';

		$envelope = $this->serializer->serialize($this->record($payload));

		$this->assertSame(
			'{"eventId":"5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01",'
			. '"eventName":"orders.order_confirmed",'
			. '"eventVersion":"1",'
			. '"aggregateType":"order",'
			. '"aggregateId":"f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10",'
			. '"aggregateVersion":7,'
			. '"occurredAt":"2026-11-10T10:34:56Z",'
			. '"payload":' . $payload . ','
			. '"correlationId":"correlation-1",'
			. '"causationId":"causation-1"}',
			$envelope
		);
	}

	#[Test]
	public function it_should_omit_absent_optional_fields(): void
	{
		$envelope = $this->serializer->serialize(
			$this->record(payload: 'null', aggregateVersion: null, correlationId: null, causationId: null,)
		);

		$this->assertSame(
			'{"eventId":"5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01",'
			. '"eventName":"orders.order_confirmed",'
			. '"eventVersion":"1",'
			. '"aggregateType":"order",'
			. '"aggregateId":"f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10",'
			. '"occurredAt":"2026-11-10T10:34:56Z",'
			. '"payload":null}',
			$envelope
		);
	}

	#[Test]
	public function it_should_preserve_the_payload_text_byte_for_byte(): void
	{
		$payload = "{ \"array\" : [1, 2.0, \"Málaga\", true, false, null], \"object\" : { \"z\" : 1, \"a\" : 2 } }\n";

		$envelope = $this->serializer->serialize($this->record($payload));

		$this->assertStringContainsString('"payload":' . $payload, $envelope);
	}

	#[Test]
	public function it_should_produce_the_same_bytes_on_repeated_serializations(): void
	{
		$record = $this->record();

		$first = $this->serializer->serialize($record);
		$second = $this->serializer->serialize($record);
		$third = $this->serializer->serialize($record);

		$this->assertSame($first, $second);
		$this->assertSame($second, $third);
	}

	#[Test]
	public function it_should_format_the_persisted_timestamp_as_iso_8601_utc(): void
	{
		$record = $this->record(occurredAt: new DateTimeImmutable('2026-11-10T23:45:01-05:00'));

		$envelope = $this->serializer->serialize($record);

		$this->assertStringContainsString('"occurredAt":"2026-11-11T04:45:01Z"', $envelope);
	}

	#[Test]
	public function it_should_keep_integer_and_decimal_representation_in_the_payload(): void
	{
		$payload = '{"integer":7,"decimal":19.90,"exponent":1e3,"negativeZero":-0}';

		$envelope = $this->serializer->serialize($this->record($payload));

		$this->assertStringContainsString('"payload":' . $payload, $envelope);
	}

	#[Test]
	public function it_should_escape_envelope_strings_without_escaping_unicode_or_slashes(): void
	{
		$record = $this->record(eventName: 'inventario.stock_añadido', correlationId: 'correlation/with"quote',);

		$envelope = $this->serializer->serialize($record);

		$this->assertStringContainsString('"eventName":"inventario.stock_añadido"', $envelope);
		$this->assertStringContainsString('"correlationId":"correlation/with\"quote"', $envelope);
	}

	#[Test]
	public function it_should_throw_a_serialization_exception_for_invalid_utf8(): void
	{
		$record = $this->record(eventName: "\xB1\x31");

		$this->expectException(OutboxSerializationException::class);
		$this->expectExceptionMessage('could not be serialized');

		$this->serializer->serialize($record);
	}

	private function record(
		string $payload = '{"sku":"A-1","quantity":2}',
		?int $aggregateVersion = 7,
		?string $correlationId = 'correlation-1',
		?string $causationId = 'causation-1',
		?DateTimeImmutable $occurredAt = null,
		string $eventName = 'orders.order_confirmed',
	): OutboxRecord {
		return new OutboxRecord(
			id: '2f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e01',
			producer: 'orders',
			eventId: self::EVENT_ID,
			eventName: $eventName,
			eventVersion: '1',
			aggregateType: 'order',
			aggregateId: self::AGGREGATE_ID,
			aggregateVersion: $aggregateVersion,
			correlationId: $correlationId,
			causationId: $causationId,
			payload: $payload,
			occurredAt: $occurredAt ?? new DateTimeImmutable('2026-11-10T12:34:56+02:00'),
			createdAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			publishedAt: null,
			attempts: 1,
			nextAttemptAt: null,
			lockedAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			claimToken: 'claim-token',
			publisherId: 'publisher-1',
			lastError: null,
			status: OutboxStatus::CLAIMED,
			failedAt: null,
		);
	}
}
