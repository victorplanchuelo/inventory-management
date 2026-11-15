<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;

final readonly class IntegrationEventQueueNameFormatter
{
	private const PREFIX = 'manager.integration.';

	public function format(IntegrationEventSubscriber $subscriber): string
	{
		return self::PREFIX . $subscriber->consumerName();
	}

	public function formatRetry(IntegrationEventSubscriber $subscriber): string
	{
		return 'retry.' . $this->format($subscriber);
	}

	public function formatDeadLetter(IntegrationEventSubscriber $subscriber): string
	{
		return 'dead_letter.' . $this->format($subscriber);
	}
}
