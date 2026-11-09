<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\StockReservation;
use Manager\Inventory\Domain\ValueObject\Quantity;

final class StockReservationMother
{
	public static function create(): StockReservation
	{
		return StockReservation::create(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(5));
	}
}
