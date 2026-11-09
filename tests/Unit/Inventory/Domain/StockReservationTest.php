<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain;

use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException;
use Manager\Inventory\Domain\StockReservation;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockReservationStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockReservationTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_create_an_active_reservation(): void
	{
		$reservationId = ReservationIdMother::create();
		$orderId = OrderIdMother::create();

		$reservation = StockReservation::create($reservationId, $orderId, new Quantity(5));

		self::assertSame($reservationId, $reservation->id());
		self::assertSame($orderId, $reservation->orderId());
		self::assertSame(5, $reservation->quantity()->value());
		self::assertSame(StockReservationStatus::Active, $reservation->status());
		self::assertTrue($reservation->isActive());
		self::assertFalse($reservation->isReleased());
	}

	#[Test]
	public function it_should_expose_its_identifiers(): void
	{
		$reservationId = ReservationIdMother::create();
		$orderId = OrderIdMother::create();

		$reservation = StockReservation::create($reservationId, $orderId, new Quantity(3));

		self::assertSame($reservationId->value(), $reservation->id()->value());
		self::assertSame($orderId->value(), $reservation->orderId()->value());
		self::assertInstanceOf(ReservationId::class, $reservation->id());
		self::assertInstanceOf(OrderId::class, $reservation->orderId());
	}

	#[Test]
	public function it_should_reject_zero_reservation_quantities(): void
	{
		$this->expectException(QuantityMustBePositiveException::class);

		StockReservation::create(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(0));
	}

	#[Test]
	public function it_should_release_an_active_reservation_and_return_a_released_instance(): void
	{
		$reservationId = ReservationIdMother::create();
		$orderId = OrderIdMother::create();
		$reservation = StockReservation::create($reservationId, $orderId, new Quantity(5));

		$released = $reservation->release();

		self::assertSame($reservationId, $released->id());
		self::assertSame($orderId, $released->orderId());
		self::assertSame(5, $released->quantity()->value());
		self::assertSame(StockReservationStatus::Released, $released->status());
		self::assertFalse($released->isActive());
		self::assertTrue($released->isReleased());
	}

	#[Test]
	public function it_should_keep_the_original_instance_unchanged_when_releasing(): void
	{
		$reservation = StockReservation::create(ReservationIdMother::create(), OrderIdMother::create(), new Quantity(5));

		$released = $reservation->release();

		self::assertNotSame($reservation, $released);
		self::assertSame(StockReservationStatus::Active, $reservation->status());
		self::assertTrue($reservation->isActive());
		self::assertFalse($reservation->isReleased());
	}

	#[Test]
	public function it_should_reject_a_repeated_release(): void
	{
		$released = StockReservation::create(
			ReservationIdMother::create(),
			OrderIdMother::create(),
			new Quantity(5)
		)->release();

		$this->expectException(ReservationAlreadyReleasedException::class);

		$released->release();
	}
}
