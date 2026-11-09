<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain;

use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyExistsException;
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
	) {}

	public static function register(StockItemId $id, ProductId $productId, Quantity $initialQuantity): self
	{
		if (!$initialQuantity->isPositive()) {
			throw new QuantityMustBePositiveException($initialQuantity->value());
		}

		$stockItem = new self($id, $productId, $initialQuantity, new Quantity(0));

		$stockItem->record(new InitialStockRegisteredDomainEvent($id, $productId->value(), $initialQuantity->value()));

		return $stockItem;
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

		$this->reservations[] = $reservation;
		$this->reserved = new Quantity($this->reserved->value() + $quantity->value());

		$this->record(
			new StockReservedDomainEvent(
				$this->id,
				$this->productId->value(),
				$orderId->value(),
				$reservationId->value(),
				$quantity->value(),
				$this->onHand->value(),
				$this->reserved->value()
			)
		);
	}
}
