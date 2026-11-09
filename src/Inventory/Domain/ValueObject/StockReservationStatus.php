<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\ValueObject;

enum StockReservationStatus: string
{
	case Active = 'active';
	case Released = 'released';
}
