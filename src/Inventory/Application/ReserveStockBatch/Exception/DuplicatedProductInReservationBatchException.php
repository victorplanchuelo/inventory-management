<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStockBatch\Exception;

use InvalidArgumentException;
use Manager\Inventory\Domain\ValueObject\ProductId;

final class DuplicatedProductInReservationBatchException extends InvalidArgumentException
{
	public function __construct(ProductId $productId)
	{
		parent::__construct(sprintf(
			'The product <%s> appears more than once in the reservation batch.',
			$productId->value()
		));
	}
}
