<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event;

use Manager\Shared\Infrastructure\Bus\CallableFirstParameterExtractor;
use Traversable;

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
}
