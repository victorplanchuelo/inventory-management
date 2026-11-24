<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\IntegrationEvent;

use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class UnsupportedInventoryDomainEvent extends DomainEvent
{
	public static function fromPrimitives(
		string $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): self {
		return new self($aggregateId, $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'inventory.unsupported';
	}

	public function toPrimitives(): array
	{
		return [];
	}
}
