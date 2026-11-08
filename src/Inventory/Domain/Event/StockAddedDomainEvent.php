<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class StockAddedDomainEvent extends DomainEvent
{
	private readonly ProductId $productId;
	private readonly Quantity $addedQuantity;
	private readonly Quantity $onHand;
	private readonly Quantity $reserved;

	public function __construct(
		private readonly StockItemId $stockItemId,
		string $productId,
		int $addedQuantity,
		int $onHand,
		int $reserved,
		?string $eventId = null,
		?string $occurredOn = null
	) {
		$productIdValueObject = new ProductId($productId);
		$addedQuantityValueObject = new Quantity($addedQuantity);
		$onHandValueObject = new Quantity($onHand);
		$reservedValueObject = new Quantity($reserved);

		if (!$addedQuantityValueObject->isPositive()) {
			throw new QuantityMustBePositiveException($addedQuantityValueObject->value());
		}

		$this->productId = $productIdValueObject;
		$this->addedQuantity = $addedQuantityValueObject;
		$this->onHand = $onHandValueObject;
		$this->reserved = $reservedValueObject;

		parent::__construct($stockItemId->value(), $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'inventory.stock.added';
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
				$body['addedQuantity'],
				$body['onHand'],
				$body['reserved']
			)
			|| !is_string($body['stockItemId'])
			|| !is_string($body['productId'])
			|| !is_int($body['addedQuantity'])
			|| !is_int($body['onHand'])
			|| !is_int($body['reserved'])
		) {
			throw new InvalidArgumentException('The stock added event payload is invalid.');
		}

		if ($body['stockItemId'] !== $aggregateId) {
			throw new InvalidArgumentException('The aggregate ID must match the stock item ID.');
		}

		return new self(
			new StockItemId($aggregateId),
			$body['productId'],
			$body['addedQuantity'],
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
			'addedQuantity' => $this->addedQuantity->value(),
			'onHand' => $this->onHand->value(),
			'reserved' => $this->reserved->value(),
		];
	}
}
