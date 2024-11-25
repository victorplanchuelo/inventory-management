<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Event\MySql;

use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentDomainEventsConsumer;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\User\Domain\ObjectMother\UserCreatedDomainEventMother;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlEloquentEventBusTest extends InfrastructureTestCase
{
	private MySqlEloquentEventBus|null $bus;
	private MySqlEloquentDomainEventsConsumer|null $consumer;

	protected function setUp(): void
	{
		parent::setUp();

		$this->bus = new MySqlEloquentEventBus();
		$this->consumer = new MySqlEloquentDomainEventsConsumer(
			new DomainEventMapping(
                []
            )
		);
	}

    #[Test] public function it_should_publish_and_consume_domain_events_from_msql(): void
	{
		$domainEvent = UserCreatedDomainEventMother::create();
        $anotherEvent = UserCreatedDomainEventMother::create();

		$this->bus->publish($domainEvent, $anotherEvent);

		$this->consumer->consume(
			subscribers: fn (DomainEvent ...$expectedEvents) => $this->assertContainsEquals($domainEvent, $expectedEvents),
			eventsToConsume: 2
		);
	}
}
