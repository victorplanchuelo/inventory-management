<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStockBatch\Exception;

use InvalidArgumentException;

final class EmptyReservationBatchException extends InvalidArgumentException
{
	public function __construct()
	{
		parent::__construct('The reservation batch must contain at least one line.');
	}
}
