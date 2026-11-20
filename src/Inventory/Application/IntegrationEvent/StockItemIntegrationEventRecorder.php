<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\IntegrationEvent;

use Manager\Inventory\Domain\StockItem;
use Manager\Shared\Application\Outbox\OutboxRecorder;

final readonly class StockItemIntegrationEventRecorder
{
	public function __construct(
		private OutboxRecorder $outboxRecorder,
		private StockItemIntegrationEventMapper $mapper
	) {}

	public function record(StockItem $stockItem): void
	{
		foreach ($stockItem->pullDomainEvents() as $domainEvent) {
			$this->outboxRecorder->append($this->mapper->map($domainEvent));
		}
	}
}
