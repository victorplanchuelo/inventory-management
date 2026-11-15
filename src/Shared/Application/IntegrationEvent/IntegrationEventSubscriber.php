<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent;

interface IntegrationEventSubscriber
{
	/**
	 * @return array<string, list<string>> Event name to supported contract versions.
	 */
	public function subscribedTo(): array;

	public function consumerName(): string;

	public function __invoke(IntegrationEventEnvelope $event): void;
}
