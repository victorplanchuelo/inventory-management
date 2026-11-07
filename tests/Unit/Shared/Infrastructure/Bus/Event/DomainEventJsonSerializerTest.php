<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event;

use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Shared\Domain\Bus\Event\FakeDomainEvent;

final class DomainEventJsonSerializerTest extends TestCase
{
	#[Test] public function it_should_serialize_the_event_identifier(): void
	{
		$eventId = UuidMother::create();
		$event = new FakeDomainEvent(UuidMother::create(), 'StockFlow', $eventId);

		$serializedEvent = $this->serialize($event);

		self::assertSame($eventId, $serializedEvent['data']['id']);
	}

	#[Test] public function it_should_serialize_the_aggregate_identifier(): void
	{
		$aggregateId = UuidMother::create();
		$event = new FakeDomainEvent($aggregateId, 'StockFlow');

		$serializedEvent = $this->serialize($event);

		self::assertSame($aggregateId, $serializedEvent['data']['attributes']['id']);
	}

	#[Test] public function it_should_keep_event_and_aggregate_identifiers_distinct(): void
	{
		$aggregateId = UuidMother::create();
		$eventId = UuidMother::create();
		$event = new FakeDomainEvent($aggregateId, 'StockFlow', $eventId);

		$serializedEvent = $this->serialize($event);

		self::assertNotSame($serializedEvent['data']['id'], $serializedEvent['data']['attributes']['id']);
	}

	private function serialize(FakeDomainEvent $event): array
	{
		return json_decode(DomainEventJsonSerializer::serialize($event), true, 512, JSON_THROW_ON_ERROR);
	}
}
