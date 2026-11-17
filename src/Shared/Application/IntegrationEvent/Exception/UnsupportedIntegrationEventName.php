<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent\Exception;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

final class UnsupportedIntegrationEventName extends PermanentIntegrationEventFailure implements IntegrationEventEnvelopeAware
{
	private function __construct(string $message, private readonly IntegrationEventEnvelope $event)
	{
		parent::__construct($message);
	}

	public static function fromEnvelope(IntegrationEventEnvelope $event): self
	{
		return new self(
			sprintf('The integration event <%s> is not supported by the consumer.', $event->eventName),
			$event
		);
	}

	public function envelope(): IntegrationEventEnvelope
	{
		return $this->event;
	}
}
