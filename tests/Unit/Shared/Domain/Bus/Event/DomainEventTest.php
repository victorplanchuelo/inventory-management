<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\Bus\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Tests\Shared\Domain\UuidMother;

final class DomainEventTest extends TestCase
{
	#[Test] public function it_should_create_an_event_with_a_uuid_aggregate_identifier(): void
	{
		$aggregateId = UuidMother::create();

		$event = new FakeDomainEvent($aggregateId, 'StockFlow');

		self::assertSame($aggregateId, $event->aggregateId());
	}

	#[Test] public function it_should_reject_an_invalid_aggregate_identifier(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new FakeDomainEvent('not-a-uuid', 'StockFlow');
	}

	#[Test] public function it_should_generate_an_event_identifier_independent_from_the_aggregate_identifier(): void
	{
		$aggregateId = UuidMother::create();

		$event = new FakeDomainEvent($aggregateId, 'StockFlow');

		self::assertNotSame($aggregateId, $event->eventId());
		self::assertTrue(Uuid::isValid($event->eventId()));
	}

	#[Test] public function it_should_preserve_an_explicitly_supplied_event_identifier(): void
	{
		$eventId = UuidMother::create();

		$event = new FakeDomainEvent(UuidMother::create(), 'StockFlow', $eventId);

		self::assertSame($eventId, $event->eventId());
	}

	#[Test] public function it_should_preserve_an_explicitly_supplied_occurrence_date(): void
	{
		$occurredOn = '2026-11-07T18:44:27+00:00';

		$event = new FakeDomainEvent(UuidMother::create(), 'StockFlow', occurredOn: $occurredOn);

		self::assertSame($occurredOn, $event->occurredOn());
	}

	#[Test]
	public function it_should_reject_an_invalid_event_identifier(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new FakeDomainEvent(UuidMother::create(), 'StockFlow', 'not-a-uuid');
	}

	#[Test]
	public function it_should_reject_an_empty_event_identifier(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new FakeDomainEvent(UuidMother::create(), 'StockFlow', '');
	}
}
