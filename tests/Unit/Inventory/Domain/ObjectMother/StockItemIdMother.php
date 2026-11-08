<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\ValueObject\StockItemId;
use Tests\Shared\Domain\UuidMother;

final class StockItemIdMother
{
	public static function create(?string $id = null): StockItemId
	{
		return new StockItemId($id ?? UuidMother::create());
	}
}
