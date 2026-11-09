<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;

final class StockReservedDomainEventTest extends \PHPUnit\Framework\TestCase
{
	#[Test]
	public function it_should_derive_the_aggregate_identifier_from_the_stock_item_id(): void
	{
		$stockItemId = StockItemIdMother::create();

		$event = new StockReservedDomainEvent(
			$stockItemId,
			ProductIdMother::create()->value(),
			UuidMother::create(),
			ReservationIdMother::create()->value(),
			5,
			100,
			5
		);

		self::assertSame($stockItemId->value(), $event->aggregateId());
	}

	#[Test]
	public function it_should_reconstruct_the_event_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$eventId = UuidMother::create();
		$occurredOn = '2026-11-08T19:00:00+00:00';

		$event = new StockReservedDomainEvent(
			$stockItemId,
			$productId->value(),
			$orderId->value(),
			$reservationId->value(),
			5,
			100,
			5,
			$eventId,
			$occurredOn
		);

		$reconstructed = StockReservedDomainEvent::fromPrimitives(
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

		StockReservedDomainEvent::fromPrimitives(
			UuidMother::create(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_zero_reserved_quantity_in_the_constructor(): void
	{
		$this->expectException(QuantityMustBePositiveException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			UuidMother::create(),
			ReservationIdMother::create()->value(),
			0,
			100,
			5
		);
	}

	#[Test]
	public function it_should_reject_negative_reserved_value_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			UuidMother::create(),
			ReservationIdMother::create()->value(),
			5,
			100,
			-1
		);
	}

	#[Test]
	public function it_should_reject_negative_on_hand_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			UuidMother::create(),
			ReservationIdMother::create()->value(),
			5,
			-1,
			0
		);
	}

	#[Test]
	public function it_should_reject_negative_reserved_quantity_in_the_constructor(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			UuidMother::create(),
			ReservationIdMother::create()->value(),
			5,
			100,
			-1
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_order_id_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			'not-a-uuid',
			ReservationIdMother::create()->value(),
			5,
			100,
			5
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_reservation_id_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			OrderIdMother::create()->value(),
			'not-a-uuid',
			5,
			100,
			5
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_order_id_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => 'not-a-uuid',
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_reservation_id_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => 'not-a-uuid',
				'reservedQuantity' => 5,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_reserved_exceeding_on_hand_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			OrderIdMother::create()->value(),
			ReservationIdMother::create()->value(),
			5,
			10,
			15
		);
	}

	#[Test]
	public function it_should_reject_a_reserved_quantity_exceeding_the_total_reserved_in_the_constructor(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new StockReservedDomainEvent(
			StockItemIdMother::create(),
			ProductIdMother::create()->value(),
			OrderIdMother::create()->value(),
			ReservationIdMother::create()->value(),
			10,
			30,
			5
		);
	}

	#[Test]
	public function it_should_reject_reserved_exceeding_on_hand_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => 10,
				'reserved' => 15,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_a_reserved_quantity_exceeding_the_total_reserved_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 10,
				'onHand' => 30,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_an_invalid_product_id_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => 'not-a-uuid',
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_zero_reserved_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(QuantityMustBePositiveException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 0,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_negative_reserved_quantity_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidQuantityException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => -1,
				'onHand' => 100,
				'reserved' => 5,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_negative_on_hand_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidQuantityException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => -1,
				'reserved' => 0,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	public function it_should_reject_negative_reserved_when_reconstructing_from_primitives(): void
	{
		$stockItemId = StockItemIdMother::create();

		$this->expectException(InvalidQuantityException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			[
				'stockItemId' => $stockItemId->value(),
				'productId' => ProductIdMother::create()->value(),
				'orderId' => OrderIdMother::create()->value(),
				'reservationId' => ReservationIdMother::create()->value(),
				'reservedQuantity' => 5,
				'onHand' => 100,
				'reserved' => -1,
			],
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

	#[Test]
	#[DataProvider('payloadFields')]
	public function it_should_reject_a_payload_missing_a_required_field(string $field): void
	{
		$stockItemId = StockItemIdMother::create();
		/** @var array<string, mixed> $payload */
		$payload = [
			'stockItemId' => $stockItemId->value(),
			'productId' => ProductIdMother::create()->value(),
			'orderId' => OrderIdMother::create()->value(),
			'reservationId' => ReservationIdMother::create()->value(),
			'reservedQuantity' => 5,
			'onHand' => 100,
			'reserved' => 5,
		];
		unset($payload[$field]);

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			$payload,
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
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
			'orderId' => OrderIdMother::create()->value(),
			'reservationId' => ReservationIdMother::create()->value(),
			'reservedQuantity' => 5,
			'onHand' => 100,
			'reserved' => 5,
		];
		$payload[$field] = $value;

		$this->expectException(InvalidArgumentException::class);

		StockReservedDomainEvent::fromPrimitives(
			$stockItemId->value(),
			$payload,
			UuidMother::create(),
			'2026-11-08T19:00:00+00:00'
		);
	}

    #[Test]
    public function it_should_reject_an_invalid_product_id_in_the_constructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StockReservedDomainEvent(
            StockItemIdMother::create(),
            'not-a-uuid',
            OrderIdMother::create()->value(),
            ReservationIdMother::create()->value(),
            5,
            100,
            5
        );
    }

	public static function payloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId'];
		yield 'product id' => ['productId'];
		yield 'order id' => ['orderId'];
		yield 'reservation id' => ['reservationId'];
		yield 'reserved quantity' => ['reservedQuantity'];
		yield 'on hand' => ['onHand'];
		yield 'reserved' => ['reserved'];
	}

	public static function wronglyTypedPayloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId', 123];
		yield 'product id' => ['productId', 123];
		yield 'order id' => ['orderId', 123];
		yield 'reservation id' => ['reservationId', 123];
		yield 'reserved quantity' => ['reservedQuantity', '5'];
		yield 'on hand' => ['onHand', '15'];
		yield 'reserved' => ['reserved', '0'];
	}
}
