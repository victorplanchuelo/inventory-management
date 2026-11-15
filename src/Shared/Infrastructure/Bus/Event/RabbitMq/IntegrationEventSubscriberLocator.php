<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;
use RuntimeException;

final readonly class IntegrationEventSubscriberLocator
{
	/** @var list<IntegrationEventSubscriber> */
	private array $subscribers;
	private IntegrationEventQueueNameFormatter $formatter;

	/**
	 * @param iterable<IntegrationEventSubscriber> $subscribers
	 */
	public function __construct(iterable $subscribers)
	{
		$this->subscribers = is_array($subscribers)
			? array_values($subscribers)
			: iterator_to_array($subscribers, false);
		$this->formatter = new IntegrationEventQueueNameFormatter();
	}

	public function withQueueNamed(string $queueName): IntegrationEventSubscriber
	{
		foreach ($this->subscribers as $subscriber) {
			if ($this->formatter->format($subscriber) === $queueName) {
				return $subscriber;
			}
		}

		throw new RuntimeException(sprintf('There are no integration-event subscribers for the <%s> queue.', $queueName));
	}

	/**
	 * @return list<IntegrationEventSubscriber>
	 */
	public function all(): array
	{
		return $this->subscribers;
	}
}
