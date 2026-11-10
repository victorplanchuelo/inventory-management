<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxMessageTest extends UnitTestCase
{
	private const EVENT_ID = '5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01';
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	#[Test]
	public function it_should_create_a_valid_message_exposing_every_field(): void
	{
		$occurredOn = new DateTimeImmutable('2026-11-10 12:34:56', new DateTimeZone('UTC'));
		$payloadJson = '{"orderId":"abc","lines":[{"sku":"A-1","quantity":2}]}';

		$message = new OutboxMessage(
			self::EVENT_ID,
			'orders.order_confirmed',
			'1',
			'orders',
			'order',
			self::AGGREGATE_ID,
			$payloadJson,
			$occurredOn,
			7,
			'correlation-1',
			'causation-1',
		);

		$this->assertSame(self::EVENT_ID, $message->eventId());
		$this->assertSame('orders.order_confirmed', $message->eventName());
		$this->assertSame('1', $message->eventVersion());
		$this->assertSame('orders', $message->producer());
		$this->assertSame('order', $message->aggregateType());
		$this->assertSame(self::AGGREGATE_ID, $message->aggregateId());
		$this->assertSame($payloadJson, $message->payloadJson());
		$this->assertSame($occurredOn, $message->occurredOn());
		$this->assertSame(7, $message->aggregateVersion());
		$this->assertSame('correlation-1', $message->correlationId());
		$this->assertSame('causation-1', $message->causationId());
	}

	#[Test]
	public function it_should_accept_absent_optional_fields(): void
	{
		$message = $this->message();

		$this->assertNull($message->aggregateVersion());
		$this->assertNull($message->correlationId());
		$this->assertNull($message->causationId());
	}

	#[Test]
	public function it_should_reject_an_invalid_event_id(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(eventId: 'not-a-uuid');
	}

	#[Test]
	public function it_should_reject_an_invalid_aggregate_id(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(aggregateId: 'not-a-uuid');
	}

	#[Test]
	public function it_should_reject_an_empty_event_name(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(eventName: '  ');
	}

	#[Test]
	public function it_should_reject_an_empty_aggregate_type(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(aggregateType: '');
	}

	#[Test]
	public function it_should_reject_an_empty_event_version(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(eventVersion: '');
	}

	#[Test]
	public function it_should_reject_an_empty_producer(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(producer: ' ');
	}

	#[Test]
	public function it_should_accept_values_at_the_maximum_lengths(): void
	{
		$eventName = str_repeat('n', 255);
		$eventVersion = str_repeat('1', 32);
		$producer = str_repeat('p', 64);
		$aggregateType = str_repeat('t', 255);
		$correlationId = str_repeat('c', 255);
		$causationId = str_repeat('d', 255);

		$message = $this->message(
			eventName: $eventName,
			eventVersion: $eventVersion,
			producer: $producer,
			aggregateType: $aggregateType,
			correlationId: $correlationId,
			causationId: $causationId,
		);

		$this->assertSame($eventName, $message->eventName());
		$this->assertSame($eventVersion, $message->eventVersion());
		$this->assertSame($producer, $message->producer());
		$this->assertSame($aggregateType, $message->aggregateType());
		$this->assertSame($correlationId, $message->correlationId());
		$this->assertSame($causationId, $message->causationId());
	}

	#[Test]
	#[DataProvider('valuesLongerThanTheMaximumLength')]
	public function it_should_reject_values_longer_than_the_maximum_lengths(string $field, string $value): void
	{
		$this->expectException(InvalidArgumentException::class);

		match ($field) {
			'eventName' => $this->message(eventName: $value),
			'eventVersion' => $this->message(eventVersion: $value),
			'producer' => $this->message(producer: $value),
			'aggregateType' => $this->message(aggregateType: $value),
			'correlationId' => $this->message(correlationId: $value),
			'causationId' => $this->message(causationId: $value),
		};
	}

	public static function valuesLongerThanTheMaximumLength(): iterable
	{
		yield 'event name' => ['eventName', str_repeat('n', 256)];
		yield 'event version' => ['eventVersion', str_repeat('1', 33)];
		yield 'producer' => ['producer', str_repeat('p', 65)];
		yield 'aggregate type' => ['aggregateType', str_repeat('t', 256)];
		yield 'correlation id' => ['correlationId', str_repeat('c', 256)];
		yield 'causation id' => ['causationId', str_repeat('d', 256)];
	}

	#[Test]
	public function it_should_count_length_limits_in_characters_not_bytes(): void
	{
		$eventName = str_repeat('é', 255);

		$message = $this->message(eventName: $eventName);

		$this->assertSame($eventName, $message->eventName());
	}

	#[Test]
	#[DataProvider('invalidJsonPayloads')]
	public function it_should_reject_invalid_json_payloads(string $invalidPayload): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(payloadJson: $invalidPayload);
	}

	public static function invalidJsonPayloads(): iterable
	{
		yield 'empty string' => [''];
		yield 'plain text' => ['not json'];
		yield 'unterminated object' => ['{"orderId":'];
		yield 'single quotes' => ["{'orderId':'abc'}"];
	}

	#[Test]
	#[DataProvider('validJsonPayloads')]
	public function it_should_accept_nested_objects_arrays_and_scalar_values_in_the_payload(string $validPayload): void
	{
		$message = $this->message(payloadJson: $validPayload);

		$this->assertSame($validPayload, $message->payloadJson());
	}

	public static function validJsonPayloads(): iterable
	{
		yield 'nested objects and arrays' => [
			'{"order":{"lines":[{"sku":"A-1","quantity":2},{"sku":"B-2","quantity":1}],"total":19.9}}',
		];
		yield 'unicode content' => ['{"customer":"José","city":"Málaga","emoji":"🐢"}'];
		yield 'numeric lexical forms' => ['{"integer":1,"decimal":1.50,"exponent":1e3,"negative":-42}'];
		yield 'booleans and nulls' => ['{"paid":true,"cancelled":false,"notes":null}'];
		yield 'top-level array' => ['[{"sku":"A-1"},{"sku":"B-2"}]'];
		yield 'preserved whitespace' => ["{ \"orderId\" : \"abc\" ,\n  \"paid\" : true }"];
	}

	#[Test]
	public function it_should_preserve_the_exact_payload_text_without_reencoding_it(): void
	{
		$payloadJson = '{ "decimal" : 1.50, "unicode" : "Málaga 🐢", "nested" : { "b" : 2, "a" : 1 } }';

		$message = $this->message(payloadJson: $payloadJson);

		$this->assertSame($payloadJson, $message->payloadJson());
	}

	#[Test]
	public function it_should_reject_a_non_positive_aggregate_version(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(aggregateVersion: 0);
	}

	#[Test]
	public function it_should_reject_an_empty_correlation_id_when_present(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(correlationId: ' ');
	}

	#[Test]
	public function it_should_reject_an_empty_causation_id_when_present(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->message(causationId: '');
	}

	private function message(
		string $eventId = self::EVENT_ID,
		string $eventName = 'inventory.stock_added',
		string $eventVersion = '1',
		string $producer = 'inventory',
		string $aggregateType = 'stock_item',
		string $aggregateId = self::AGGREGATE_ID,
		string $payloadJson = '{"sku":"A-1","quantity":2}',
		?DateTimeImmutable $occurredOn = null,
		?int $aggregateVersion = null,
		?string $correlationId = null,
		?string $causationId = null,
	): OutboxMessage {
		return new OutboxMessage(
			$eventId,
			$eventName,
			$eventVersion,
			$producer,
			$aggregateType,
			$aggregateId,
			$payloadJson,
			$occurredOn ?? new DateTimeImmutable('2026-11-10 12:34:56', new DateTimeZone('UTC')),
			$aggregateVersion,
			$correlationId,
			$causationId,
		);
	}
}
