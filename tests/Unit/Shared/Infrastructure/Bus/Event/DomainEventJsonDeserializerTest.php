<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event;

use InvalidArgumentException;
use Manager\Shared\Domain\Utils;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonSerializer;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Shared\Domain\Bus\Event\FakeDomainEvent;
use Tests\Unit\Shared\Domain\Bus\Event\FakeDomainEventSubscriber;

final class DomainEventJsonDeserializerTest extends TestCase
{
	private DomainEventJsonDeserializer $deserializer;

	protected function setUp(): void
	{
		$this->deserializer = new DomainEventJsonDeserializer(new DomainEventMapping([new FakeDomainEventSubscriber()]));
	}

	#[Test] public function it_should_reconstruct_the_correct_event(): void
	{
		$event = new FakeDomainEvent(UuidMother::create(), 'StockFlow');

		$deserializedEvent = $this->roundTrip($event);

		self::assertInstanceOf(FakeDomainEvent::class, $deserializedEvent);
	}

	#[Test] public function it_should_preserve_uuid_aggregate_identifiers_after_a_round_trip(): void
	{
		$aggregateId = UuidMother::create();
		$event = new FakeDomainEvent($aggregateId, 'StockFlow');

		$deserializedEvent = $this->roundTrip($event);

		self::assertSame($aggregateId, $deserializedEvent->aggregateId());
	}

	#[Test] public function it_should_preserve_event_attributes_after_a_round_trip(): void
	{
		$occurredOn = '2026-11-07T18:44:27+00:00';
		$event = new FakeDomainEvent(UuidMother::create(), 'StockFlow', occurredOn: $occurredOn);

		$deserializedEvent = $this->roundTrip($event);

		self::assertSame('StockFlow', $deserializedEvent->name());
		self::assertSame($occurredOn, $deserializedEvent->occurredOn());
	}

	#[Test] public function it_should_not_confuse_event_and_aggregate_identifiers_after_a_round_trip(): void
	{
		$aggregateId = UuidMother::create();
		$eventId = UuidMother::create();
		$event = new FakeDomainEvent($aggregateId, 'StockFlow', $eventId);

		$deserializedEvent = $this->roundTrip($event);

		self::assertSame($aggregateId, $deserializedEvent->aggregateId());
		self::assertSame($eventId, $deserializedEvent->eventId());
		self::assertNotSame($deserializedEvent->aggregateId(), $deserializedEvent->eventId());
	}

	#[Test] public function it_should_reject_a_legacy_numeric_aggregate_identifier_without_converting_it(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The domain event aggregate identifier <1> must be a valid UUID.');

		$this->deserializer->deserialize($this->serializedEventWithAggregateId(1));
	}

	#[Test] public function it_should_reject_a_numeric_string_aggregate_identifier_without_converting_it(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The domain event aggregate identifier <1> must be a valid UUID.');

		$this->deserializer->deserialize($this->serializedEventWithAggregateId('1'));
	}

	#[Test]
	public function it_should_reject_an_invalid_uuid_aggregate_identifier(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The domain event aggregate identifier <invalid-uuid> must be a valid UUID.');

		$this->deserializer->deserialize($this->serializedEventWithAggregateId('invalid-uuid'));
	}

	#[Test]
	public function it_should_reject_a_null_aggregate_identifier(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The domain event aggregate identifier <null> must be a valid UUID.');

		$this->deserializer->deserialize($this->serializedEventWithAggregateId(null));
	}

	private function roundTrip(FakeDomainEvent $event): FakeDomainEvent
	{
		$deserializedEvent = $this->deserializer->deserialize(DomainEventJsonSerializer::serialize($event));

		self::assertInstanceOf(FakeDomainEvent::class, $deserializedEvent);

		return $deserializedEvent;
	}

	private function serializedEventWithAggregateId(mixed $aggregateId): string
	{
		return Utils::jsonEncode(
			[
				'data' => [
					'id' => UuidMother::create(),
					'type' => FakeDomainEvent::eventName(),
					'occurred_on' => '2026-11-07T18:44:27+00:00',
					'attributes' => [
						'id' => $aggregateId,
						'name' => 'StockFlow',
					],
				],
				'meta' => [],
			]
		);
	}
}
