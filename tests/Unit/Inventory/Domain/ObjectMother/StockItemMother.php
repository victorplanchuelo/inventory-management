<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;

final class StockItemMother
{
	public static function create(
		?StockItemId $id = null,
		?ProductId $productId = null,
		?Quantity $initialQuantity = null
	): StockItem {
		return StockItem::register(
			$id ?? StockItemIdMother::create(),
			$productId ?? ProductIdMother::create(),
			$initialQuantity ?? QuantityMother::create()
		);
	}
}
