<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\ValueObject\Quantity;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockItemTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_register_a_stock_item_with_initial_stock(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$initialQuantity = new Quantity(25);

		$stockItem = StockItem::register($stockItemId, $productId, $initialQuantity);

		self::assertSame($stockItemId, $stockItem->id());
		self::assertSame($productId, $stockItem->productId());
		self::assertSame($initialQuantity, $stockItem->onHand());
		self::assertSame(0, $stockItem->reserved()->value());
		self::assertSame(25, $stockItem->available()->value());
	}

	#[Test]
	public function it_should_reject_zero_initial_stock(): void
	{
		$this->expectException(QuantityMustBePositiveException::class);

		StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(0));
	}

	#[Test]
	public function it_should_calculate_available_stock_from_on_hand_minus_reserved(): void
	{
		$stockItem = StockItem::register(
			StockItemIdMother::create(),
			ProductIdMother::create(),
			QuantityMother::create(40)
		);

		self::assertSame(
			$stockItem->onHand()->value() - $stockItem->reserved()->value(),
			$stockItem->available()->value()
		);
	}

	#[Test]
	public function it_should_expose_its_identifiers(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();

		$stockItem = StockItem::register($stockItemId, $productId, QuantityMother::create());

		self::assertSame($stockItemId->value(), $stockItem->id()->value());
		self::assertSame($productId->value(), $stockItem->productId()->value());
	}

	#[Test]
	public function it_should_record_an_initial_stock_registered_domain_event(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();

		$stockItem = StockItem::register($stockItemId, $productId, new Quantity(15));

		$events = $stockItem->pullDomainEvents();

		self::assertCount(1, $events);
		self::assertInstanceOf(InitialStockRegisteredDomainEvent::class, $events[0]);
		self::assertSame($stockItemId->value(), $events[0]->aggregateId());
		self::assertSame(
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => $productId->value(),
				'initialQuantity' => 15,
			],
			$events[0]->toPrimitives()
		);
	}

	#[Test]
	public function it_should_add_stock_and_increase_on_hand_and_available(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(10));
		$stockItem->pullDomainEvents();

		$stockItem->addStock(new Quantity(5));

		self::assertSame(15, $stockItem->onHand()->value());
		self::assertSame(0, $stockItem->reserved()->value());
		self::assertSame(15, $stockItem->available()->value());
	}

	#[Test]
	public function it_should_reject_zero_stock_additions(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(10));

		$this->expectException(QuantityMustBePositiveException::class);

		$stockItem->addStock(new Quantity(0));
	}

	#[Test]
	public function it_should_record_a_stock_added_domain_event(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$stockItem = StockItem::register($stockItemId, $productId, new Quantity(10));
		$stockItem->pullDomainEvents();

		$stockItem->addStock(new Quantity(5));

		$events = $stockItem->pullDomainEvents();

		self::assertCount(1, $events);
		self::assertInstanceOf(StockAddedDomainEvent::class, $events[0]);
		self::assertSame($stockItemId->value(), $events[0]->aggregateId());
		self::assertSame(
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => $productId->value(),
				'addedQuantity' => 5,
				'onHand' => 15,
				'reserved' => 0,
			],
			$events[0]->toPrimitives()
		);
	}

	#[Test]
	public function it_should_reserve_stock_from_available_quantity(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(25));
		$stockItem->pullDomainEvents();

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(10));

		self::assertTrue($stockItem->reservations()[0]->isActive());
		self::assertSame(25, $stockItem->onHand()->value());
		self::assertSame(10, $stockItem->reserved()->value());
		self::assertSame(15, $stockItem->available()->value());
	}

	#[Test]
	public function it_should_reserve_exactly_all_available_stock(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(25));
		$stockItem->pullDomainEvents();

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(25));

		self::assertSame(0, $stockItem->available()->value());
		self::assertSame(25, $stockItem->onHand()->value());
		self::assertSame(25, $stockItem->reserved()->value());
		self::assertCount(1, $stockItem->reservations());
	}

	#[Test]
	public function it_should_leave_the_aggregate_unchanged_when_a_reservation_id_is_duplicated(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(25));
		$stockItem->pullDomainEvents();
		$reservationId = ReservationIdMother::create();

		$stockItem->reserve($reservationId, OrderIdMother::create(), new Quantity(10));
		self::assertCount(1, $stockItem->pullDomainEvents());

		try {
			$stockItem->reserve($reservationId, OrderIdMother::create(), new Quantity(5));

			self::fail('A ReservationAlreadyExistsException was expected.');
		} catch (ReservationAlreadyExistsException) {
		}

		self::assertSame(10, $stockItem->reserved()->value());
		self::assertSame(15, $stockItem->available()->value());
		self::assertCount(1, $stockItem->reservations());
		self::assertCount(0, $stockItem->pullDomainEvents());
	}

	#[Test]
	public function it_should_leave_the_aggregate_unchanged_when_a_reservation_exceeds_available_stock(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(15));
		$stockItem->pullDomainEvents();

		try {
			$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(20));

			self::fail('An InsufficientStockException was expected.');
		} catch (InsufficientStockException) {
		}

		self::assertSame(0, $stockItem->reserved()->value());
		self::assertSame(15, $stockItem->available()->value());
		self::assertCount(0, $stockItem->reservations());
		self::assertCount(0, $stockItem->pullDomainEvents());
	}

	#[Test]
	public function it_should_reject_zero_reservation_quantity(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(25));

		$this->expectException(QuantityMustBePositiveException::class);

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(0));
	}

	#[Test]
	public function it_should_reject_a_second_reservation_that_reuses_an_existing_reservation_id(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(25));
		$reservationId = ReservationIdMother::create();

		$stockItem->reserve($reservationId, OrderIdMother::create(), new Quantity(10));

		$this->expectException(ReservationAlreadyExistsException::class);

		$stockItem->reserve($reservationId, OrderIdMother::create(), new Quantity(5));
	}

	#[Test]
	public function it_should_reject_a_reservation_exceeding_available_stock(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(20));

		$this->expectException(InsufficientStockException::class);

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(30));
	}

	#[Test]
	public function it_should_update_reserved_and_available_correctly(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(50));
		$stockItem->pullDomainEvents();

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(20));

		self::assertSame(20, $stockItem->reserved()->value());
		self::assertSame(30, $stockItem->available()->value());
	}

	#[Test]
	public function it_should_keep_the_sum_of_active_reservation_quantities_equal_to_reserved(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(100));
		$stockItem->pullDomainEvents();

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(20));
		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(10));

		$activeReservationQuantities = 0;

		foreach ($stockItem->reservations() as $reservation) {
			$activeReservationQuantities += $reservation->quantity()->value();
		}

		self::assertSame(30, $activeReservationQuantities);
		self::assertSame(30, $stockItem->reserved()->value());
	}

	#[Test]
	public function it_should_return_a_list_copy_from_reservations(): void
	{
		$stockItem = StockItem::register(StockItemIdMother::create(), ProductIdMother::create(), new Quantity(50));
		$stockItem->pullDomainEvents();

		$stockItem->reserve(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(20));

		$reservations = $stockItem->reservations();
		$reservations[] = $reservations[0];

		self::assertCount(1, $stockItem->reservations());
	}

	#[Test]
	public function it_should_record_a_stock_reserved_domain_event(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$stockItem = StockItem::register($stockItemId, $productId, new Quantity(30));
		$stockItem->pullDomainEvents();

		$stockItem->reserve($reservationId, $orderId, new Quantity(15));

		$events = $stockItem->pullDomainEvents();

		self::assertCount(1, $events);
		self::assertInstanceOf(StockReservedDomainEvent::class, $events[0]);
		self::assertSame($stockItemId->value(), $events[0]->aggregateId());
		self::assertSame(
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => $productId->value(),
				'orderId' => $orderId->value(),
				'reservationId' => $reservationId->value(),
				'reservedQuantity' => 15,
				'onHand' => 30,
				'reserved' => 15,
			],
			$events[0]->toPrimitives()
		);
	}
}
