<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Get;

use Manager\Shared\Domain\Bus\Query\Query;

final readonly class GetInventoryAvailabilityQuery implements Query
{
	public function __construct(private string $productId) {}

	public function productId(): string
	{
		return $this->productId;
	}
}
