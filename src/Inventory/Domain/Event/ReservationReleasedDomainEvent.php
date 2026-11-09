<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class ReservationReleasedDomainEvent extends DomainEvent
{
	private readonly ProductId $productId;
	private readonly OrderId $orderId;
	private readonly ReservationId $reservationId;
	private readonly Quantity $releasedQuantity;
	private readonly Quantity $onHand;
	private readonly Quantity $reserved;

	public function __construct(
		private readonly StockItemId $stockItemId,
		string $productId,
		string $orderId,
		string $reservationId,
		int $releasedQuantity,
		int $onHand,
		int $reserved,
		?string $eventId = null,
		?string $occurredOn = null
	) {
		$productIdValueObject = new ProductId($productId);
		$orderIdValueObject = new OrderId($orderId);
		$reservationIdValueObject = new ReservationId($reservationId);
		$releasedQuantityValueObject = new Quantity($releasedQuantity);
		$onHandValueObject = new Quantity($onHand);
		$reservedValueObject = new Quantity($reserved);

		if (!$releasedQuantityValueObject->isPositive()) {
			throw new QuantityMustBePositiveException($releasedQuantityValueObject->value());
		}

		if ($reservedValueObject->isBiggerThan($onHandValueObject)) {
			throw new InvalidArgumentException('The reserved quantity cannot exceed the on hand quantity.');
		}

		if ($releasedQuantityValueObject->value() + $reservedValueObject->value() > $onHandValueObject->value()) {
			throw new InvalidArgumentException('The released quantity cannot exceed the available stock.');
		}

		$this->productId = $productIdValueObject;
		$this->orderId = $orderIdValueObject;
		$this->reservationId = $reservationIdValueObject;
		$this->releasedQuantity = $releasedQuantityValueObject;
		$this->onHand = $onHandValueObject;
		$this->reserved = $reservedValueObject;

		parent::__construct($stockItemId->value(), $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'inventory.reservation.released';
	}

	public static function fromPrimitives(
		string $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): self {
		if (
			!isset(
				$body['stockItemId'],
				$body['productId'],
				$body['orderId'],
				$body['reservationId'],
				$body['releasedQuantity'],
				$body['onHand'],
				$body['reserved']
			)
			|| !is_string($body['stockItemId'])
			|| !is_string($body['productId'])
			|| !is_string($body['orderId'])
			|| !is_string($body['reservationId'])
			|| !is_int($body['releasedQuantity'])
			|| !is_int($body['onHand'])
			|| !is_int($body['reserved'])
		) {
			throw new InvalidArgumentException('The reservation released event payload is invalid.');
		}

		if ($body['stockItemId'] !== $aggregateId) {
			throw new InvalidArgumentException('The aggregate ID must match the stock item ID.');
		}

		return new self(
			new StockItemId($aggregateId),
			$body['productId'],
			$body['orderId'],
			$body['reservationId'],
			$body['releasedQuantity'],
			$body['onHand'],
			$body['reserved'],
			$eventId,
			$occurredOn
		);
	}

	public function toPrimitives(): array
	{
		return [
			'stockItemId' => $this->stockItemId->value(),
			'productId' => $this->productId->value(),
			'orderId' => $this->orderId->value(),
			'reservationId' => $this->reservationId->value(),
			'releasedQuantity' => $this->releasedQuantity->value(),
			'onHand' => $this->onHand->value(),
			'reserved' => $this->reserved->value(),
		];
	}
}
