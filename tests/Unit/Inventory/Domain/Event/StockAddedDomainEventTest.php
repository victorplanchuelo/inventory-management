<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockAddedDomainEventTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_derive_the_aggregate_identifier_from_the_stock_item_id(): void
	{
		$stockItemId = StockItemIdMother::create();

		$event = new StockAddedDomainEvent($stockItemId, ProductIdMother::create()->value(), 5, 15, 0);

		self::assertSame($stockItemId->value(), $event->aggregateId());
	}

	#[Test]
	public function it_should_reconstruct_the_event_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$eventId = UuidMother::create();
		$occurredOn = '2026-11-08T18:30:00+00:00';

		$event = new StockAddedDomainEvent($stockItemId, $productId->value(), 5, 15, 2, $eventId, $occurredOn);

		$reconstructed = StockAddedDomainEvent::fromPrimitives(
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
	public function it_should_reject_a_stock_item_id_that_does_not_match_the_aggregate_id(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockAddedDomainEvent::fromPrimitives(
			UuidMother::create(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'addedQuantity' => 5,
				'onHand' => 15,
				'reserved' => 0,
			],
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_product_id_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new StockAddedDomainEvent(StockItemIdMother::create(), 'not-a-uuid', 5, 15, 0);
	}

	#[Test]
	public function it_should_reject_zero_added_quantity_in_the_constructor(): void
	{
		$this->expectException(QuantityMustBePositiveException::class);

		new StockAddedDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), 0, 15, 0);
	}

	#[Test]
	public function it_should_reject_negative_added_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockAddedDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), -1, 15, 0);
	}

	#[Test]
	public function it_should_reject_negative_on_hand_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockAddedDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), 5, -1, 0);
	}

	#[Test]
	public function it_should_reject_negative_reserved_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockAddedDomainEvent(StockItemIdMother::create(), ProductIdMother::create()->value(), 5, 15, -1);
	}

	#[Test]
	public function it_should_reject_an_invalid_product_id_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockAddedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => 'not-a-uuid',
				'addedQuantity' => 5,
				'onHand' => 15,
				'reserved' => 0,
			],
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_zero_added_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(QuantityMustBePositiveException::class);

		StockAddedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'addedQuantity' => 0,
				'onHand' => 15,
				'reserved' => 0,
			],
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_negative_added_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidQuantityException::class);

		StockAddedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'addedQuantity' => -1,
				'onHand' => 15,
				'reserved' => 0,
			],
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	#[Test]
	#[DataProvider('missingPayloadFields')]
	public function it_should_reject_a_payload_missing_a_required_field(string $field): void
	{
		$stockItemId = StockItemIdMother::create();
		/** @var array<string, mixed> $payload */
		$payload = [
			'stockItemId' => $stockItemId->value(),
			'productId' => ProductIdMother::create()->value(),
			'addedQuantity' => 5,
			'onHand' => 15,
			'reserved' => 0,
		];
		unset($payload[$field]);

		$this->expectException(InvalidArgumentException::class);

		StockAddedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			$payload,
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function missingPayloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId'];
		yield 'product id' => ['productId'];
		yield 'added quantity' => ['addedQuantity'];
		yield 'on hand' => ['onHand'];
		yield 'reserved' => ['reserved'];
	}

	#[Test]
	#[DataProvider('wronglyTypedPayloadFields')]
	public function it_should_reject_a_payload_with_a_wrong_primitive_type(string $field, mixed $value): void
	{
		$stockItemId = StockItemIdMother::create();
		/** @var array<string, mixed> $payload */
		$payload = [
			'stockItemId' => $stockItemId->value(),
			'productId' => ProductIdMother::create()->value(),
			'addedQuantity' => 5,
			'onHand' => 15,
			'reserved' => 0,
		];
		$payload[$field] = $value;

		$this->expectException(InvalidArgumentException::class);

		StockAddedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			$payload,
			UuidMother::create(),
			'2026-11-08T18:30:00+00:00'
		);
	}

	/**
	 * @return iterable<string, array{string, mixed}>
	 */
	public static function wronglyTypedPayloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId', 123];
		yield 'product id' => ['productId', 123];
		yield 'added quantity' => ['addedQuantity', '5'];
		yield 'on hand' => ['onHand', '15'];
		yield 'reserved' => ['reserved', '0'];
	}
}
