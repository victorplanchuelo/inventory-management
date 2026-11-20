<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;

final class InventorySchemaConstraintsTest extends InventoryIntegrationTestCase
{
	#[Test]
	public function it_should_reject_a_negative_on_hand_value(): void
	{
		$this->expectException(QueryException::class);

		$this->insertStockItem($this->newStockItemId(), $this->newProductId(), -1);
	}

	#[Test]
	public function it_should_reject_a_negative_reserved_value(): void
	{
		$this->expectException(QueryException::class);

		$this->insertStockItem($this->newStockItemId(), $this->newProductId(), 10, -1);
	}

	#[Test]
	public function it_should_reject_reserved_greater_than_on_hand(): void
	{
		$this->expectException(QueryException::class);

		$this->insertStockItem($this->newStockItemId(), $this->newProductId(), 5, 6);
	}

	#[Test]
	public function it_should_reject_a_zero_reservation_quantity(): void
	{
		$stockItemId = $this->newStockItemId();
		$this->insertStockItem($stockItemId, $this->newProductId(), 10);

		$this->expectException(QueryException::class);

		$this->insertReservation($this->newReservationId(), $stockItemId, 0);
	}

	#[Test]
	public function it_should_reject_a_negative_reservation_quantity(): void
	{
		$stockItemId = $this->newStockItemId();
		$this->insertStockItem($stockItemId, $this->newProductId(), 10);

		$this->expectException(QueryException::class);

		$this->insertReservation($this->newReservationId(), $stockItemId, -1);
	}

	#[Test]
	public function it_should_reject_an_unknown_reservation_status(): void
	{
		$stockItemId = $this->newStockItemId();
		$this->insertStockItem($stockItemId, $this->newProductId(), 10);

		$this->expectException(QueryException::class);

		$this->insertReservation($this->newReservationId(), $stockItemId, 1, 'unknown');
	}

	#[Test]
	public function it_should_accept_active_and_released_reservation_statuses(): void
	{
		$stockItemId = $this->newStockItemId();
		$firstReservationId = $this->newReservationId();
		$secondReservationId = $this->newReservationId();
		$this->insertStockItem($stockItemId, $this->newProductId(), 10);
		$this->insertReservation($firstReservationId, $stockItemId, 2, 'active');
		$this->insertReservation($secondReservationId, $stockItemId, 3, 'released');

		$rows = $this->connection->table('inventory_stock_reservations')
			->whereIn('id', [$firstReservationId, $secondReservationId])
			->orderBy('id')
			->get();

		self::assertCount(2, $rows);
		self::assertSame('active', $rows->firstWhere('id', $firstReservationId)->status);
		self::assertSame('released', $rows->firstWhere('id', $secondReservationId)->status);
	}

	#[Test]
	public function it_should_reject_deleting_a_stock_item_that_still_has_reservations(): void
	{
		$stockItemId = $this->newStockItemId();
		$this->insertStockItem($stockItemId, $this->newProductId(), 10);
		$this->insertReservation($this->newReservationId(), $stockItemId, 1);

		$this->expectException(QueryException::class);

		$this->connection->table('inventory_stock_items')
			->where('id', $stockItemId)
			->delete();
	}

	#[Test]
	public function it_should_enforce_a_unique_product_identifier(): void
	{
		$productId = $this->newProductId();
		$this->insertStockItem($this->newStockItemId(), $productId, 10);

		$this->expectException(QueryException::class);

		$this->insertStockItem($this->newStockItemId(), $productId, 5);
	}

	private function insertStockItem(string $id, string $productId, int $onHand, int $reserved = 0): void
	{
		$this->trackStockItem($id);

		$this->connection->table('inventory_stock_items')->insert([
			'id' => $id,
			'product_id' => $productId,
			'on_hand' => $onHand,
			'reserved' => $reserved,
			'created_at' => '2026-11-19 00:00:00',
			'updated_at' => '2026-11-19 00:00:00',
		]);
	}

	private function insertReservation(
		string $id,
		string $stockItemId,
		int $quantity,
		string $status = 'active'
	): void {
		$this->trackReservation($id);

		$this->connection->table('inventory_stock_reservations')->insert([
			'id' => $id,
			'stock_item_id' => $stockItemId,
			'order_id' => $this->newOrderId(),
			'quantity' => $quantity,
			'status' => $status,
			'created_at' => '2026-11-19 00:00:00',
			'updated_at' => '2026-11-19 00:00:00',
		]);
	}

	private function newStockItemId(): string
	{
		return UuidMother::create();
	}

	private function newProductId(): string
	{
		return UuidMother::create();
	}

	private function newReservationId(): string
	{
		return UuidMother::create();
	}

	private function newOrderId(): string
	{
		return UuidMother::create();
	}
}
