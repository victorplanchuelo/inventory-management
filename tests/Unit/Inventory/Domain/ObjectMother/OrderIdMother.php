<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\ValueObject\OrderId;
use Tests\Shared\Domain\UuidMother;

final class OrderIdMother
{
	public static function create(?string $id = null): OrderId
	{
		return new OrderId($id ?? UuidMother::create());
	}
}
