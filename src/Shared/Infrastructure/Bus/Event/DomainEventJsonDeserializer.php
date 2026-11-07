<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event;

use InvalidArgumentException;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Domain\Utils;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class DomainEventJsonDeserializer
{
	public function __construct(private DomainEventMapping $mapping) {}

	public function deserialize(string $domainEvent): DomainEvent
	{
		$eventData = Utils::jsonDecode($domainEvent);
		$eventName = $eventData['data']['type'];
		$eventClass = $this->mapping->for($eventName);
		$aggregateId = $eventData['data']['attributes']['id'] ?? null;

		if (!is_string($aggregateId)) {
			throw new InvalidArgumentException(
				sprintf(
					'The domain event aggregate identifier <%s> must be a valid UUID.',
					is_scalar($aggregateId) ? (string) $aggregateId : get_debug_type($aggregateId)
				)
			);
		}

		try {
			new SimpleUuid($aggregateId);
		} catch (InvalidArgumentException $exception) {
			throw new InvalidArgumentException(
				sprintf('The domain event aggregate identifier <%s> must be a valid UUID.', $aggregateId),
				previous: $exception
			);
		}

		return $eventClass::fromPrimitives(
			$aggregateId,
			$eventData['data']['attributes'],
			$eventData['data']['id'],
			$eventData['data']['occurred_on']
		);
	}
}
