<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\ValueObject\ProductId;
use Tests\Shared\Domain\UuidMother;

final class ProductIdMother
{
	public static function create(?string $id = null): ProductId
	{
		return new ProductId($id ?? UuidMother::create());
	}
}
