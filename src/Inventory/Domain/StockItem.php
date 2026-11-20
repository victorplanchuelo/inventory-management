<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain;

use InvalidArgumentException;
use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Event\ReservationReleasedDomainEvent;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyExistsException;
use Manager\Inventory\Domain\Exception\ReservationNotFoundException;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Aggregate\AggregateRoot;

final class StockItem extends AggregateRoot
{
	/**
	 * @param list<StockReservation> $reservations
	 */
	private function __construct(
		private readonly StockItemId $id,
		private readonly ProductId $productId,
		private Quantity $onHand,
		private Quantity $reserved,
		private array $reservations = []
	) {
		$this->ensureReservedDoesNotExceedOnHand();
		$this->ensureReservationsAreValid();
	}

	public static function register(StockItemId $id, ProductId $productId, Quantity $initialQuantity): self
	{
		if (!$initialQuantity->isPositive()) {
			throw new QuantityMustBePositiveException($initialQuantity->value());
		}

		$stockItem = new self($id, $productId, $initialQuantity, new Quantity(0));

		$stockItem->record(new InitialStockRegisteredDomainEvent($id, $productId->value(), $initialQuantity->value()));

		return $stockItem;
	}

	/**
	 * @param list<StockReservation> $reservations
	 */
	public static function reconstitute(
		StockItemId $id,
		ProductId $productId,
		Quantity $onHand,
		Quantity $reserved,
		array $reservations
	): self {
		return new self($id, $productId, $onHand, $reserved, $reservations);
	}

	public function id(): StockItemId
	{
		return $this->id;
	}

	public function productId(): ProductId
	{
		return $this->productId;
	}

	public function onHand(): Quantity
	{
		return $this->onHand;
	}

	public function reserved(): Quantity
	{
		return $this->reserved;
	}

	public function addStock(Quantity $quantity): void
	{
		if (!$quantity->isPositive()) {
			throw new QuantityMustBePositiveException($quantity->value());
		}

		$this->onHand = new Quantity($this->onHand->value() + $quantity->value());

		$this->record(
			new StockAddedDomainEvent(
				$this->id,
				$this->productId->value(),
				$quantity->value(),
				$this->onHand->value(),
				$this->reserved->value()
			)
		);
	}

	public function available(): Quantity
	{
		return new Quantity($this->onHand->value() - $this->reserved->value());
	}

	/**
	 * @return list<StockReservation>
	 */
	public function reservations(): array
	{
		return $this->reservations;
	}

	public function reserve(ReservationId $reservationId, OrderId $orderId, Quantity $quantity): void
	{
		if (!$quantity->isPositive()) {
			throw new QuantityMustBePositiveException($quantity->value());
		}

		foreach ($this->reservations as $existing) {
			if ($existing->id()->equals($reservationId)) {
				throw new ReservationAlreadyExistsException($reservationId);
			}
		}

		if ($quantity->isBiggerThan($this->available())) {
			throw new InsufficientStockException($this->productId, $quantity, $this->available());
		}

		$reservation = StockReservation::create($reservationId, $orderId, $quantity);
		$reserved = new Quantity($this->reserved->value() + $quantity->value());

		$event = new StockReservedDomainEvent(
			$this->id,
			$this->productId->value(),
			$orderId->value(),
			$reservationId->value(),
			$quantity->value(),
			$this->onHand->value(),
			$reserved->value()
		);

		$this->reservations[] = $reservation;
		$this->reserved = $reserved;

		$this->record($event);
	}

	public function releaseReservation(ReservationId $reservationId): void
	{
		$index = null;

		foreach ($this->reservations as $key => $existing) {
			if ($existing->id()->equals($reservationId)) {
				$index = $key;
				break;
			}
		}

		if ($index === null) {
			throw new ReservationNotFoundException($reservationId);
		}

		$reservation = $this->reservations[$index];

		$releasedReservation = $reservation->release();

		$releasedCounter = new Quantity($this->reserved->value() - $reservation->quantity()->value());

		$releasedEvent = new ReservationReleasedDomainEvent(
			$this->id,
			$this->productId->value(),
			$reservation->orderId()->value(),
			$reservationId->value(),
			$reservation->quantity()->value(),
			$this->onHand->value(),
			$releasedCounter->value()
		);

		$this->reservations[$index] = $releasedReservation;
		$this->reserved = $releasedCounter;

		$this->record($releasedEvent);
	}

	private function ensureReservedDoesNotExceedOnHand(): void
	{
		if ($this->reserved->isBiggerThan($this->onHand)) {
			throw new InvalidArgumentException(sprintf(
				'The reserved quantity <%d> cannot exceed the on hand quantity <%d>.',
				$this->reserved->value(),
				$this->onHand->value()
			));
		}
	}

	private function ensureReservationsAreValid(): void
	{
		$reservationIds = [];
		$activeReservationQuantity = 0;

		foreach ($this->reservations as $reservation) {
			if (!$this->isStockReservation($reservation)) {
				throw new InvalidArgumentException('Every reservation must be a StockReservation instance.');
			}

			$reservationId = $reservation->id()->value();

			if (isset($reservationIds[$reservationId])) {
				throw new InvalidArgumentException(sprintf(
					'Every reservation identifier must be unique inside the aggregate. Duplicated <%s>.',
					$reservationId
				));
			}

			$reservationIds[$reservationId] = true;

			if ($reservation->isActive()) {
				$activeReservationQuantity += $reservation->quantity()->value();
			}
		}

		if ($activeReservationQuantity !== $this->reserved->value()) {
			throw new InvalidArgumentException(sprintf(
				'The reserved quantity <%d> must equal the sum of active reservation quantities <%d>.',
				$this->reserved->value(),
				$activeReservationQuantity
			));
		}
	}

	private function isStockReservation(mixed $reservation): bool
	{
		return $reservation instanceof StockReservation;
	}
}
