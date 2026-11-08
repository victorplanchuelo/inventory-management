<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\RegisterInitialStock;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class RegisterInitialStockCommand implements Command
{
	public function __construct(public string $productId, public int $initialQuantity) {}

	public function productId(): string
	{
		return $this->productId;
	}

	public function initialQuantity(): int
	{
		return $this->initialQuantity;
	}
}
