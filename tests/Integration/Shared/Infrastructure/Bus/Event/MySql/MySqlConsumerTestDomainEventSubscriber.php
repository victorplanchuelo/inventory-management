<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\MySql;

use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;

final class MySqlConsumerTestDomainEventSubscriber implements DomainEventSubscriber
{
	public static function subscribedTo(): array
	{
		return [MySqlConsumerTestDomainEvent::class];
	}
}
