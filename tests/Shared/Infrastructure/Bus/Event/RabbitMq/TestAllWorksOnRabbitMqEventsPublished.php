<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Api\User\Domain\UserCreatedDomainEvent;
use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;

final class TestAllWorksOnRabbitMqEventsPublished implements DomainEventSubscriber
{
	public static function subscribedTo(): array
	{
		return [UserCreatedDomainEvent::class, ];
	}

	public function __invoke(UserCreatedDomainEvent $event): void {}
}
