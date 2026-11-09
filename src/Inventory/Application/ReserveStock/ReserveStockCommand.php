<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStock;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class ReserveStockCommand implements Command
{
	public function __construct(public string $productId, public string $orderId, public int $quantity) {}

	public function productId(): string
	{
		return $this->productId;
	}

	public function orderId(): string
	{
		return $this->orderId;
	}

	public function quantity(): int
	{
		return $this->quantity;
	}
}
