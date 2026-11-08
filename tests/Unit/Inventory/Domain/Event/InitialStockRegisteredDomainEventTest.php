<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class InitialStockRegisteredDomainEventTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_derive_the_aggregate_identifier_from_the_stock_item_id(): void
	{
		$stockItemId = StockItemIdMother::create();

		$event = new InitialStockRegisteredDomainEvent($stockItemId, ProductIdMother::create()->value(), 10);

		self::assertSame($stockItemId->value(), $event->aggregateId());
	}

	#[Test]
	public function it_should_reconstruct_the_event_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$eventId = UuidMother::create();
		$occurredOn = '2026-11-08T17:07:38+00:00';

		$event = new InitialStockRegisteredDomainEvent($stockItemId, $productId->value(), 12, $eventId, $occurredOn);

		$reconstructed = InitialStockRegisteredDomainEvent::fromPrimitives(
			$event->aggregateId(),
			$event->toPrimitives(),
			$event->eventId(),
			$event->occurredOn()
		);

		self::assertSame($event->toPrimitives(), $reconstructed->toPrimitives());
		self::assertSame($eventId, $reconstructed->eventId());
		self::assertSame($occurredOn, $reconstructed->occurredOn());
	}

	#[Test]
	public function it_should_reject_an_invalid_product_id_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new InitialStockRegisteredDomainEvent(StockItemIdMother::create(), 'not-a-uuid', 10);
	}

	#[Test]
	public function it_should_reject_zero_initial_quantity_in_the_constructor(): void
	{
		$this->expectException(QuantityMustBePositiveException::class);

		new InitialStockRegisteredDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), 0);
	}

	#[Test]
	public function it_should_reject_negative_initial_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new InitialStockRegisteredDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), -1);
	}

	#[Test]
	public function it_should_reject_an_invalid_product_id_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		InitialStockRegisteredDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => 'not-a-uuid',
				'initialQuantity' => 10,
			],
			UuidMother::create(),
			'2026-11-08T17:07:38+00:00'
		);
	}

	#[Test]
	public function it_should_reject_zero_initial_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(QuantityMustBePositiveException::class);

		InitialStockRegisteredDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'initialQuantity' => 0,
			],
			UuidMother::create(),
			'2026-11-08T17:07:38+00:00'
		);
	}

	#[Test]
	public function it_should_reject_negative_initial_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidQuantityException::class);

		InitialStockRegisteredDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'initialQuantity' => -1,
			],
			UuidMother::create(),
			'2026-11-08T17:07:38+00:00'
		);
	}

	#[Test]
	public function it_should_reject_a_stock_item_id_that_does_not_match_the_aggregate_id(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		InitialStockRegisteredDomainEvent::fromPrimitives(
			UuidMother::create(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'initialQuantity' => 10,
			],
			UuidMother::create(),
			'2026-11-08T17:07:38+00:00'
		);
	}
}
