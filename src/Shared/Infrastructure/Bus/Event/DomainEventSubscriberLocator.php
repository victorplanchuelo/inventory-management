<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event;

use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;
use Manager\Shared\Infrastructure\Bus\CallableFirstParameterExtractor;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqQueueNameFormatter;
use RuntimeException;
use Traversable;

use function Lambdish\Phunctional\search;

final readonly class DomainEventSubscriberLocator
{
	private array $mapping;

	public function __construct(Traversable $mapping)
	{
		$this->mapping = iterator_to_array($mapping);
	}

	public function allSubscribedTo(string $eventClass): array
	{
		$formatted = CallableFirstParameterExtractor::forPipedCallables($this->mapping);

		return $formatted[$eventClass];
	}

	public function all(): array
	{
		return $this->mapping;
	}

    public function withRabbitMqQueueNamed(string $queueName): callable | DomainEventSubscriber
    {
        $subscriber = search(
            static fn (DomainEventSubscriber $subscriber): bool => RabbitMqQueueNameFormatter::format($subscriber) ===
                $queueName,
            $this->mapping
        );

        if ($subscriber === null) {
            throw new RuntimeException("There are no subscribers for the <$queueName> queue");
        }

        return $subscriber;
    }
}
