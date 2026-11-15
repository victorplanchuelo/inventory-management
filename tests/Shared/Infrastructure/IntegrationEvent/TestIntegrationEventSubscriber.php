<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\IntegrationEvent;

use Closure;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;

final class TestIntegrationEventSubscriber implements IntegrationEventSubscriber
{
	private readonly Closure $handler;

	/**
	 * @param array<string, list<string>> $subscriptions
	 * @param callable(IntegrationEventEnvelope): void $handler
	 */
	public function __construct(
		private readonly string $consumerName,
		private readonly array $subscriptions,
		callable $handler,
	) {
		$this->handler = Closure::fromCallable($handler);
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function subscribedTo(): array
	{
		return $this->subscriptions;
	}

	public function consumerName(): string
	{
		return $this->consumerName;
	}

	public function __invoke(IntegrationEventEnvelope $event): void
	{
		($this->handler)($event);
	}
}
