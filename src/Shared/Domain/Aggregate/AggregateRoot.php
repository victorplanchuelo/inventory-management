<?php

declare(strict_types=1);

namespace Manager\Shared\Domain\Aggregate;

use Manager\Shared\Domain\Bus\Event\DomainEvent;

abstract class AggregateRoot
{
	private array $domainEvents = [];

	final public function pullDomainEvents(): array
	{
		$domainEvents = $this->domainEvents;
		$this->domainEvents = [];

		return $domainEvents;
	}

	final protected function record(DomainEvent $domainEvent): void
	{
		$this->domainEvents[] = $domainEvent;
	}
}
