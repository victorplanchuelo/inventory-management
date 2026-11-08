<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain;

use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;

interface StockItemRepository
{
	public function search(StockItemId $id): ?StockItem;

	public function searchByProductId(ProductId $productId): ?StockItem;

	public function searchByReservationId(ReservationId $reservationId): ?StockItem;

	public function save(StockItem $stockItem): void;
}
