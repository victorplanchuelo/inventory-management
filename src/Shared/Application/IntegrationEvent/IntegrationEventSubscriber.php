<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent;

use Manager\Shared\Application\Inbox\AggregateVersionPolicy;

interface IntegrationEventSubscriber
{
	/**
	 * @return array<string, list<string>> Event name to supported contract versions.
	 */
	public function subscribedTo(): array;

	public function consumerName(): string;

	public function orderingPolicy(): AggregateVersionPolicy;

	public function __invoke(IntegrationEventEnvelope $event): void;
}
