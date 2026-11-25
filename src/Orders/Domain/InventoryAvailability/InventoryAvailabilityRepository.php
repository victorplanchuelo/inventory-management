<?php

declare(strict_types=1);

namespace Manager\Orders\Domain\InventoryAvailability;

interface InventoryAvailabilityRepository
{
	public function findByProductId(string $productId): ?InventoryAvailability;

	public function apply(InventoryAvailability $availability): void;
}
