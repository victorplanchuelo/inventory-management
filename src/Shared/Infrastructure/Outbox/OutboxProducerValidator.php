<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

final readonly class OutboxProducerValidator
{
	/** @param list<string> $registeredProducers */
	public function __construct(private array $registeredProducers) {}

	public function assertIsRegistered(string $producer): void
	{
		if (!in_array($producer, $this->registeredProducers, true)) {
			throw UnknownOutboxProducerException::named($producer, $this->registeredProducers);
		}
	}
}
