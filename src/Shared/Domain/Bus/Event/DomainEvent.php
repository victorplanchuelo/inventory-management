<?php

declare(strict_types=1);

namespace Manager\Shared\Domain\Bus\Event;

use DateTimeImmutable;
use Manager\Shared\Domain\Utils;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

abstract class DomainEvent
{
	private readonly string $aggregateId;
	private readonly string $eventId;
	private readonly string $occurredOn;

	public function __construct(string $aggregateId, ?string $eventId = null, ?string $occurredOn = null)
	{
		$this->aggregateId = (new SimpleUuid($aggregateId))->value();
		$this->eventId = $eventId !== null
			? (new SimpleUuid($eventId))->value()
			: SimpleUuid::random()->value();
		$this->occurredOn = $occurredOn ?: Utils::dateToString(new DateTimeImmutable());
	}

	abstract public static function fromPrimitives(
		string $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): self;

	abstract public static function eventName(): string;

	abstract public function toPrimitives(): array;

	final public function aggregateId(): string
	{
		return $this->aggregateId;
	}

	final public function eventId(): string
	{
		return $this->eventId;
	}

	final public function occurredOn(): string
	{
		return $this->occurredOn;
	}
}
