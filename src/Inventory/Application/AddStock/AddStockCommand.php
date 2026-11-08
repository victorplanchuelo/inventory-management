<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\AddStock;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class AddStockCommand implements Command
{
	public function __construct(public string $productId, public int $quantity) {}

	public function productId(): string
	{
		return $this->productId;
	}

	public function quantity(): int
	{
		return $this->quantity;
	}
}
