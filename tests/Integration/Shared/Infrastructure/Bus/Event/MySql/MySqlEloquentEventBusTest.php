<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\MySql;

use Illuminate\Support\Facades\DB;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentDomainEventsConsumer;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\User\Domain\ObjectMother\UserCreatedDomainEventMother;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlEloquentEventBusTest extends InfrastructureTestCase
{
	private MySqlEloquentEventBus | null $bus;
	private MySqlEloquentDomainEventsConsumer | null $consumer;

	protected function setUp(): void
	{
		parent::setUp();

		DB::table('domain_events')->delete();

		$this->bus = new MySqlEloquentEventBus();
		$this->consumer = new MySqlEloquentDomainEventsConsumer(
			new DomainEventMapping([new UserCreatedDomainEventSubscriber()])
		);
	}

	#[Test] public function it_should_publish_and_consume_domain_events_from_mysql(): void
	{
		$domainEvent = UserCreatedDomainEventMother::create();

		$this->bus->publish($domainEvent);

		$persistedEvent = DB::table('domain_events')
			->where('id', $domainEvent->eventId())
			->first();

		$this->assertNotNull($persistedEvent);
		$this->assertSame($domainEvent->eventId(), $persistedEvent->id);
		$this->assertSame($domainEvent->aggregateId(), $persistedEvent->aggregate_id);
		$this->assertSame('user.created', $persistedEvent->name);

		$consumedEvents = [];
		$this->consumer->consume(
			subscribers: function (DomainEvent $event) use (&$consumedEvents): void {
				$consumedEvents[] = $event;
			},
			eventsToConsume: 1
		);

		$this->assertCount(1, $consumedEvents);

		$consumedEvent = $consumedEvents[0];

		$this->assertSame($domainEvent->eventId(), $consumedEvent->eventId());
		$this->assertSame($domainEvent->aggregateId(), $consumedEvent->aggregateId());
		$this->assertNotSame($consumedEvent->eventId(), $consumedEvent->aggregateId());
	}

	protected function tearDown(): void
	{
		DB::table('domain_events')->delete();

		parent::tearDown();
	}
}
