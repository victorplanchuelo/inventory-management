<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event;

use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Domain\Utils;

final readonly class DomainEventJsonDeserializer
{
	public function __construct(private DomainEventMapping $mapping) {}

	public function deserialize(string $domainEvent): DomainEvent
	{
		$eventData = Utils::jsonDecode($domainEvent);
		$eventName = $eventData['data']['type'];
		$eventClass = $this->mapping->for($eventName);

		return $eventClass::fromPrimitives(
			$eventData['data']['attributes']['id'],
			$eventData['data']['attributes'],
			$eventData['data']['id'],
			$eventData['data']['occurred_on']
		);
	}
}
