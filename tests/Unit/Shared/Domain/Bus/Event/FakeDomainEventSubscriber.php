<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\Bus\Event;

use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;

final class FakeDomainEventSubscriber implements DomainEventSubscriber
{
	public static function subscribedTo(): array
	{
		return [FakeDomainEvent::class];
	}
}
