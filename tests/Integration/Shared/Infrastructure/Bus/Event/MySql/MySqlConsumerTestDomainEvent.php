<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\MySql;

use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class MySqlConsumerTestDomainEvent extends DomainEvent
{
	public function __construct(
		string $aggregateId,
		private readonly string $value,
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
		return new self($aggregateId, $body['value'], $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'test.event';
	}

	public function toPrimitives(): array
	{
		return ['value' => $this->value];
	}
}
