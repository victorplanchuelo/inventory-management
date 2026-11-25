<?php

declare(strict_types=1);

namespace Tests\Unit\Orders\Application\Support;

use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;

final class RecordingInventoryAvailabilityRepository implements InventoryAvailabilityRepository
{
	/** @var list<InventoryAvailability> */
	private array $applied = [];

	public function findByProductId(string $productId): ?InventoryAvailability
	{
		foreach ($this->applied as $availability) {
			if ($availability->productId() === $productId) {
				return $availability;
			}
		}

		return null;
	}

	public function apply(InventoryAvailability $availability): void
	{
		$this->applied[] = $availability;
	}

	/**
	 * @return list<InventoryAvailability>
	 */
	public function applied(): array
	{
		return $this->applied;
	}
}
