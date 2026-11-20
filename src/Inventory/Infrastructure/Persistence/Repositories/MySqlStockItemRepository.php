<?php

declare(strict_types=1);

namespace Manager\Inventory\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use LogicException;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockItemEloquentModel;
use Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockReservationEloquentModel;
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;

final readonly class MySqlStockItemRepository implements StockItemRepository, StockItemExclusiveReader
{
	public function __construct(
		private Connection $connection,
		private StockItemPersistenceMapper $mapper
	) {}

	public function search(StockItemId $id): ?StockItem
	{
		return $this->searchOne($this->stockItems()->whereKey($id->value()));
	}

	public function searchByProductId(ProductId $productId): ?StockItem
	{
		return $this->searchOne($this->stockItems()->where('product_id', $productId->value()));
	}

	public function searchByReservationId(ReservationId $reservationId): ?StockItem
	{
		$stockItemId = $this->reservations()
			->whereKey($reservationId->value())
			->value('stock_item_id');

		return is_string($stockItemId) ? $this->search(new StockItemId($stockItemId)) : null;
	}

	public function save(StockItem $stockItem): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$existingStockItem = $this->stockItems()
			->whereKey($stockItem->id()->value())
			->first();

		if ($existingStockItem instanceof StockItemEloquentModel) {
			$this->stockItems()
				->whereKey($stockItem->id()->value())
				->update($this->mapper->stockItemUpdate($stockItem, $now));
		} else {
			$this->insertStockItem($stockItem, $now);
		}

		$this->saveReservations($stockItem, $now);
	}

	public function readByIdForUpdate(StockItemId $id): ?StockItem
	{
		$this->ensureTransactionIsActive();

		return $this->locked($this->stockItems()->whereKey($id->value()));
	}

	public function readByProductIdForUpdate(ProductId $productId): ?StockItem
	{
		$this->ensureTransactionIsActive();

		return $this->locked($this->stockItems()->where('product_id', $productId->value()));
	}

	public function readByReservationIdForUpdate(ReservationId $reservationId): ?StockItem
	{
		$this->ensureTransactionIsActive();

		$stockItemId = $this->reservations()
			->whereKey($reservationId->value())
			->value('stock_item_id');

		if (!is_string($stockItemId)) {
			return null;
		}

		return $this->readByIdForUpdate(new StockItemId($stockItemId));
	}

	/**
	 * @param Builder<StockItemEloquentModel> $query
	 */
	private function searchOne(Builder $query): ?StockItem
	{
		$model = $query->first();

		return $model instanceof StockItemEloquentModel ? $this->mapper->toDomain($model) : null;
	}

	/**
	 * @param Builder<StockItemEloquentModel> $query
	 */
	private function locked(Builder $query): ?StockItem
	{
		$model = $query->lockForUpdate()->first();

		if (!$model instanceof StockItemEloquentModel) {
			return null;
		}

		$model->load([
			'reservations' => static function (HasMany $relation): void {
				$relation->lockForUpdate();
			},
		]);

		return $this->mapper->toDomain($model);
	}

	private function insertStockItem(StockItem $stockItem, DateTimeImmutable $now): void
	{
		try {
			$this->stockItems()->insert($this->mapper->stockItemRow($stockItem, $now, $now));
		} catch (QueryException $exception) {
			if ($this->isDuplicateProductIdViolation($exception)) {
				throw new StockItemAlreadyExistsException($stockItem->productId());
			}

			throw $exception;
		}
	}

	private function saveReservations(StockItem $stockItem, DateTimeImmutable $now): void
	{
		$existingReservations = $this->reservations()
			->where('stock_item_id', $stockItem->id()->value())
			->get()
			->keyBy('id');

		foreach ($stockItem->reservations() as $reservation) {
			$existingReservation = $existingReservations->get($reservation->id()->value());

			if ($existingReservation instanceof StockReservationEloquentModel) {
				$this->reservations()
					->whereKey($reservation->id()->value())
					->update($this->mapper->reservationUpdate($reservation, $now));

				continue;
			}

			$this->reservations()->insert(
				$this->mapper->reservationRow($reservation, $stockItem->id()->value(), $now, $now)
			);
		}
	}

	private function ensureTransactionIsActive(): void
	{
		if ($this->connection->transactionLevel() === 0) {
			throw new LogicException('Stock item exclusive reads require an active transaction.');
		}
	}

	private function isDuplicateProductIdViolation(QueryException $exception): bool
	{
		return (string) $exception->getCode() === '23000'
			&& str_contains($exception->getMessage(), 'inventory_stock_items_product_id_unique');
	}

	/**
	 * @return Builder<StockItemEloquentModel>
	 */
	private function stockItems(): Builder
	{
		$model = new StockItemEloquentModel();
		$model->setConnection($this->connection->getName());

		return $model->newQuery();
	}

	/**
	 * @return Builder<StockReservationEloquentModel>
	 */
	private function reservations(): Builder
	{
		$model = new StockReservationEloquentModel();
		$model->setConnection($this->connection->getName());

		return $model->newQuery();
	}
}
