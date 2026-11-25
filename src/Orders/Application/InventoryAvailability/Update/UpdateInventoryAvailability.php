<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Update;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;

final readonly class UpdateInventoryAvailability
{
	public function __construct(private InventoryAvailabilityRepository $repository) {}

	public function __invoke(InventoryStockState $state): void
	{
		$this->repository->apply(new InventoryAvailability(
			$state->productId,
			$state->stockItemId,
			$state->onHand,
			$state->reserved,
			$state->occurredAt,
			new DateTimeImmutable('now', new DateTimeZone('UTC')),
		));
	}
}
