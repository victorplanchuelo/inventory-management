<?php

declare(strict_types=1);

namespace Manager\Inventory\Infrastructure\Persistence\Mappers;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockReservation;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Domain\ValueObject\StockReservationStatus;
use Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockItemEloquentModel;
use Manager\Inventory\Infrastructure\Persistence\Eloquent\Models\StockReservationEloquentModel;

final readonly class StockItemPersistenceMapper
{
	public function toDomain(StockItemEloquentModel $model): StockItem
	{
		$reservations = [];

		foreach ($model->reservations as $reservationModel) {
			$reservations[] = $this->reservationToDomain($reservationModel);
		}

		return StockItem::reconstitute(
			new StockItemId($model->id),
			new ProductId($model->product_id),
			new Quantity($model->on_hand),
			new Quantity($model->reserved),
			$reservations
		);
	}

	/**
	 * @return array{id: string, product_id: string, on_hand: int, reserved: int, created_at: string, updated_at: string}
	 */
	public function stockItemRow(
		StockItem $stockItem,
		DateTimeImmutable $createdAt,
		DateTimeImmutable $updatedAt
	): array {
		return [
			'id' => $stockItem->id()->value(),
			'product_id' => $stockItem->productId()->value(),
			'on_hand' => $stockItem->onHand()->value(),
			'reserved' => $stockItem->reserved()->value(),
			'created_at' => $this->utcDateTime($createdAt),
			'updated_at' => $this->utcDateTime($updatedAt),
		];
	}

	/**
	 * @return array{on_hand: int, reserved: int, updated_at: string}
	 */
	public function stockItemUpdate(StockItem $stockItem, DateTimeImmutable $updatedAt): array
	{
		return [
			'on_hand' => $stockItem->onHand()->value(),
			'reserved' => $stockItem->reserved()->value(),
			'updated_at' => $this->utcDateTime($updatedAt),
		];
	}

	/**
	 * @return array{id: string, stock_item_id: string, order_id: string, quantity: int, status: string, created_at: string, updated_at: string}
	 */
	public function reservationRow(
		StockReservation $reservation,
		string $stockItemId,
		DateTimeImmutable $createdAt,
		DateTimeImmutable $updatedAt
	): array {
		return [
			'id' => $reservation->id()->value(),
			'stock_item_id' => $stockItemId,
			'order_id' => $reservation->orderId()->value(),
			'quantity' => $reservation->quantity()->value(),
			'status' => $reservation->status()->value,
			'created_at' => $this->utcDateTime($createdAt),
			'updated_at' => $this->utcDateTime($updatedAt),
		];
	}

	/**
	 * @return array{quantity: int, status: string, updated_at: string}
	 */
	public function reservationUpdate(StockReservation $reservation, DateTimeImmutable $updatedAt): array
	{
		return [
			'quantity' => $reservation->quantity()->value(),
			'status' => $reservation->status()->value,
			'updated_at' => $this->utcDateTime($updatedAt),
		];
	}

	private function reservationToDomain(StockReservationEloquentModel $model): StockReservation
	{
		return StockReservation::reconstitute(
			new ReservationId($model->id),
			new OrderId($model->order_id),
			new Quantity($model->quantity),
			StockReservationStatus::from($model->status)
		);
	}

	private function utcDateTime(DateTimeImmutable $dateTime): string
	{
		return $dateTime
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d H:i:s');
	}
}
