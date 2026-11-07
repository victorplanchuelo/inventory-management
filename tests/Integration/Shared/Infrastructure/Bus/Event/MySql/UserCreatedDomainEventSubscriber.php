<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\MySql;

use Manager\Api\User\Domain\UserCreatedDomainEvent;
use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;

final class UserCreatedDomainEventSubscriber implements DomainEventSubscriber
{
	public static function subscribedTo(): array
	{
		return [UserCreatedDomainEvent::class];
	}
}
