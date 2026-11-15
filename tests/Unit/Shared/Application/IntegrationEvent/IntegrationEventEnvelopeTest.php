<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\IntegrationEvent;

use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class IntegrationEventEnvelopeTest extends UnitTestCase
{
	#[Test]
	public function it_should_accept_the_exact_producer_occurred_at_format(): void
	{
		$envelope = IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data());

		$this->assertSame(IntegrationEventFixture::EVENT_ID, $envelope->eventId);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $envelope->eventName);
		$this->assertSame(IntegrationEventFixture::EVENT_VERSION, $envelope->eventVersion);
		$this->assertSame('stock_item', $envelope->aggregateType);
		$this->assertSame(IntegrationEventFixture::AGGREGATE_ID, $envelope->aggregateId);
		$this->assertSame(3, $envelope->aggregateVersion);
		$this->assertSame(IntegrationEventFixture::OCCURRED_AT, $envelope->occurredAt->format('Y-m-d\TH:i:s\Z'));
		$this->assertSame(['sku' => 'A-1', 'quantity' => 2], $envelope->payload);
		$this->assertSame('correlation-1', $envelope->correlationId);
		$this->assertSame('causation-1', $envelope->causationId);
	}

	#[Test]
	public function it_should_accept_an_opaque_non_uuid_aggregate_id(): void
	{
		$envelope = IntegrationEventEnvelope::fromArray(
			IntegrationEventFixture::data(['aggregateId' => 'order/2026/11/0001'])
		);

		$this->assertSame('order/2026/11/0001', $envelope->aggregateId);
	}

	#[Test]
	public function it_should_require_a_uuid_event_id(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data(['eventId' => 'not-a-uuid']));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidOccurredAtFormats(): iterable
	{
		yield 'microseconds' => ['2026-11-10T12:34:56.123Z'];
		yield 'offset' => ['2026-11-10T12:34:56+01:00'];
		yield 'missing Z' => ['2026-11-10T12:34:56'];
		yield 'space separator' => ['2026-11-10 12:34:56Z'];
		yield 'local time with Z' => ['2026-11-10T13:34:56+01:00'];
		yield 'invalid date' => ['2026-02-30T12:34:56Z'];
		yield 'integer' => [12345];
	}

	#[Test]
	#[DataProvider('invalidOccurredAtFormats')]
	public function it_should_reject_occurred_at_values_outside_the_exact_producer_format(mixed $occurredAt): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data(['occurredAt' => $occurredAt]));
	}

	#[Test]
	public function it_should_reject_a_missing_required_field(): void
	{
		$data = IntegrationEventFixture::data();
		unset($data['eventName']);

		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray($data);
	}

	#[Test]
	public function it_should_reject_a_non_object_payload(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data(['payload' => 'not-an-object']));
	}

	#[Test]
	public function it_should_reject_a_non_positive_aggregate_version(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data(['aggregateVersion' => 0]));
	}

	#[Test]
	public function it_should_allow_absent_optional_metadata_and_aggregate_version(): void
	{
		$data = IntegrationEventFixture::data();
		unset($data['aggregateVersion'], $data['correlationId'], $data['causationId']);

		$envelope = IntegrationEventEnvelope::fromArray($data);

		$this->assertNull($envelope->aggregateVersion);
		$this->assertNull($envelope->correlationId);
		$this->assertNull($envelope->causationId);
	}

	#[Test]
	public function it_should_reject_an_event_name_longer_than_the_column(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		IntegrationEventEnvelope::fromArray(IntegrationEventFixture::data(['eventName' => str_repeat('a', 256)]));
	}
}
