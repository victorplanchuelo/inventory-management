<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\Stock;

use LogicException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;

/**
 * Read port for use cases that must modify a StockItem aggregate.
 *
 * Every method must run inside an already-active transaction. The adapter throws
 * a LogicException before issuing any query when no transaction is active, and
 * keeps the row lock until the surrounding transaction commits or rolls back.
 */
interface StockItemExclusiveReader
{
	/**
	 * @throws LogicException When no transaction is active.
	 */
	public function readByIdForUpdate(StockItemId $id): ?StockItem;

	/**
	 * @throws LogicException When no transaction is active.
	 */
	public function readByProductIdForUpdate(ProductId $productId): ?StockItem;

	/**
	 * @throws LogicException When no transaction is active.
	 */
	public function readByReservationIdForUpdate(ReservationId $reservationId): ?StockItem;
}
