<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Domain\DomainError;

final class StockItemAlreadyExistsException extends DomainError
{
	public function __construct(private readonly ProductId $productId)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'stock_item_already_exists';
	}

	protected function errorMessage(): string
	{
		return sprintf('The stock item for product <%s> already exists.', $this->productId->value());
	}
}
