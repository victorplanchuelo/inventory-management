<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStockBatch;

final readonly class ReserveStockLine
{
	public function __construct(public string $productId, public int $quantity) {}
}
