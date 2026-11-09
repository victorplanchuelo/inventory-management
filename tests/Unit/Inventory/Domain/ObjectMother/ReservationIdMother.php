<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ObjectMother;

use Manager\Inventory\Domain\ValueObject\ReservationId;
use Tests\Shared\Domain\UuidMother;

final class ReservationIdMother
{
	public static function create(?string $id = null): ReservationId
	{
		return new ReservationId($id ?? UuidMother::create());
	}
}
