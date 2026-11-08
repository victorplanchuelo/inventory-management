<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Domain\DomainError;

final class StockItemNotFoundException extends DomainError
{
	public function __construct(private readonly ProductId $productId)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'stock_item_not_found';
	}

	protected function errorMessage(): string
	{
		return sprintf('The stock item for product <%s> was not found.', $this->productId->value());
	}
}
