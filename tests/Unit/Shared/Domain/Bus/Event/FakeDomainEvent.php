<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\Bus\Event;

use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class FakeDomainEvent extends DomainEvent
{
	public function __construct(
		string $aggregateId,
		private readonly string $name,
		?string $eventId = null,
		?string $occurredOn = null
	) {
		parent::__construct($aggregateId, $eventId, $occurredOn);
	}

	public static function fromPrimitives(
		string $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): self {
		return new self($aggregateId, $body['name'], $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'fake.event';
	}

	public function toPrimitives(): array
	{
		return [
			'name' => $this->name,
		];
	}

	public function name(): string
	{
		return $this->name;
	}
}
