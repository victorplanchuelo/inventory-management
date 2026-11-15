<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

interface IntegrationEventFailurePublisher
{
	/**
	 * @param array<string, mixed> $properties
	 */
	public function publish(string $exchangeName, string $routingKey, string $body, array $properties): void;
}
