<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application\Support;

use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use RuntimeException;

final readonly class FailingStockItemRepository implements StockItemRepository
{
	public function __construct(private StockItemRepository $repository) {}

	public function search(StockItemId $id): ?StockItem
	{
		return $this->repository->search($id);
	}

	public function searchByProductId(ProductId $productId): ?StockItem
	{
		return $this->repository->searchByProductId($productId);
	}

	public function searchByReservationId(ReservationId $reservationId): ?StockItem
	{
		return $this->repository->searchByReservationId($reservationId);
	}

	public function save(StockItem $stockItem): void
	{
		$this->repository->save($stockItem);

		throw new RuntimeException('Repository write failed.');
	}
}
