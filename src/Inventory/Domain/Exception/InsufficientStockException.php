<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Shared\Domain\DomainError;

final class InsufficientStockException extends DomainError
{
	public function __construct(private readonly ProductId $productId, private readonly Quantity $requested, private readonly Quantity $available)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'insufficient_stock';
	}

	protected function errorMessage(): string
	{
		return sprintf(
			'The requested quantity <%d> exceeds the available stock of <%d> for product <%s>.',
			$this->requested->value(),
			$this->available->value(),
			$this->productId->value()
		);
	}
}
