<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\ValueObject\Quantity;
use Tests\Shared\Domain\IntegerMother;

final class QuantityMother
{
	public static function create(?int $value = null): Quantity
	{
		return new Quantity($value ?? IntegerMother::create());
	}
}
