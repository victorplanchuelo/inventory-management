<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use JsonException;
use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventName;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventVersion;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;

final readonly class IntegrationEventEnvelopeDeserializer
{
	public function deserialize(string $body, IntegrationEventSubscriber $subscriber): IntegrationEventEnvelope
	{
		$trimmedBody = ltrim($body);

		if ($trimmedBody === '' || $trimmedBody[0] !== '{') {
			throw InvalidIntegrationEventEnvelope::becauseItIsNotAnObject();
		}

		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw InvalidIntegrationEventEnvelope::becauseJsonIsInvalid();
		}

		if (!is_array($data)) {
			throw InvalidIntegrationEventEnvelope::becauseItIsNotAnObject();
		}

		$event = IntegrationEventEnvelope::fromArray($data);
		$subscriptions = $subscriber->subscribedTo();

		if (!array_key_exists($event->eventName, $subscriptions)) {
			throw UnsupportedIntegrationEventName::fromEnvelope($event);
		}

		if (!in_array($event->eventVersion, $subscriptions[$event->eventName], true)) {
			throw UnsupportedIntegrationEventVersion::fromEnvelope($event);
		}

		return $event;
	}
}
