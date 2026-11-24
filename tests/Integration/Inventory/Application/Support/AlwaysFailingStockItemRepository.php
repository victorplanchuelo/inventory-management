<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application\Support;

use Illuminate\Database\QueryException;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use PDOException;

final class AlwaysFailingStockItemRepository implements StockItemRepository, StockItemExclusiveReader
{
	/** @var list<StockItem> */
	public array $readStockItems = [];

	/** @var list<StockItem> */
	public array $savedStockItems = [];

	public function __construct(private readonly MySqlStockItemRepository $repository) {}

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
		$this->savedStockItems[] = $stockItem;

		$this->repository->save($stockItem);

		throw new QueryException(
			'inventory_retry_probe',
			'update inventory_stock_items set on_hand = ?',
			[],
			new PDOException('Deadlock found when trying to get lock; try restarting transaction')
		);
	}

	public function readByIdForUpdate(StockItemId $id): ?StockItem
	{
		return $this->trackRead($this->repository->readByIdForUpdate($id));
	}

	public function readByProductIdForUpdate(ProductId $productId): ?StockItem
	{
		return $this->trackRead($this->repository->readByProductIdForUpdate($productId));
	}

	public function readByReservationIdForUpdate(ReservationId $reservationId): ?StockItem
	{
		return $this->trackRead($this->repository->readByReservationIdForUpdate($reservationId));
	}

	private function trackRead(?StockItem $stockItem): ?StockItem
	{
		if ($stockItem !== null) {
			$this->readStockItems[] = $stockItem;
		}

		return $stockItem;
	}
}
