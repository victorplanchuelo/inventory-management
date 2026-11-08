<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\ValueObject\Quantity;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
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
}
