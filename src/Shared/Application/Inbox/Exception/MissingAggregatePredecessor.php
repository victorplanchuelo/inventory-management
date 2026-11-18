<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use Manager\Shared\Application\IntegrationEvent\Exception\RetryableIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

final class MissingAggregatePredecessor extends RetryableIntegrationEventFailure
{
	public static function forEvent(
		string $consumer,
		IntegrationEventEnvelope $event,
		?int $lastAppliedVersion
	): self {
		return new self(
			sprintf(
				'The consumer <%s> cannot process event <%s> for aggregate <%s/%s> at version <%s> because the last processed version is <%s>.',
				$consumer,
				$event->eventId,
				$event->aggregateType,
				$event->aggregateId,
				self::formatVersion($event->aggregateVersion),
				self::formatVersion($lastAppliedVersion)
			)
		);
	}

	private static function formatVersion(?int $version): string
	{
		return $version === null ? 'missing' : (string) $version;
	}
}
