<?php

declare(strict_types=1);

namespace Tests\Integration\Orders\Infrastructure\IntegrationEvent\Support;

use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use RuntimeException;

final readonly class FailingInventoryAvailabilityRepository implements InventoryAvailabilityRepository
{
	public function __construct(private InventoryAvailabilityRepository $decorated) {}

	public function findByProductId(string $productId): ?InventoryAvailability
	{
		return $this->decorated->findByProductId($productId);
	}

	public function apply(InventoryAvailability $availability): void
	{
		throw new RuntimeException('projection write failed');
	}
}
