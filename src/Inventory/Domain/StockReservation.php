<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain;

use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockReservationStatus;

final class StockReservation
{
	private function __construct(
		private readonly ReservationId $id,
		private readonly OrderId $orderId,
		private readonly Quantity $quantity,
		private readonly StockReservationStatus $status
	) {}

	public static function create(ReservationId $id, OrderId $orderId, Quantity $quantity): self
	{
		if (!$quantity->isPositive()) {
			throw new QuantityMustBePositiveException($quantity->value());
		}

		return new self($id, $orderId, $quantity, StockReservationStatus::Active);
	}

	public function id(): ReservationId
	{
		return $this->id;
	}

	public function orderId(): OrderId
	{
		return $this->orderId;
	}

	public function quantity(): Quantity
	{
		return $this->quantity;
	}

	public function status(): StockReservationStatus
	{
		return $this->status;
	}

	public function isActive(): bool
	{
		return $this->status === StockReservationStatus::Active;
	}

	public function isReleased(): bool
	{
		return $this->status === StockReservationStatus::Released;
	}

	public function release(): self
	{
		if ($this->isReleased()) {
			throw new ReservationAlreadyReleasedException($this->id);
		}

		return new self($this->id, $this->orderId, $this->quantity, StockReservationStatus::Released);
	}
}
