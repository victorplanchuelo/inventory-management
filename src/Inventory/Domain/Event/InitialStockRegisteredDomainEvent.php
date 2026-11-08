<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Event;

use InvalidArgumentException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class InitialStockRegisteredDomainEvent extends DomainEvent
{
	private readonly ProductId $productId;
	private readonly Quantity $initialQuantity;

	public function __construct(
		private readonly StockItemId $stockItemId,
		string $productId,
		int $initialQuantity,
		?string $eventId = null,
		?string $occurredOn = null
	) {
		$productIdValueObject = new ProductId($productId);
		$initialQuantityValueObject = new Quantity($initialQuantity);

		if (!$initialQuantityValueObject->isPositive()) {
			throw new QuantityMustBePositiveException($initialQuantityValueObject->value());
		}

		$this->productId = $productIdValueObject;
		$this->initialQuantity = $initialQuantityValueObject;

		parent::__construct($stockItemId->value(), $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'inventory.stock_item.registered';
	}

	public static function fromPrimitives(
		string $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): self {
		if (
			!isset($body['stockItemId'], $body['productId'], $body['initialQuantity'])
			|| !is_string($body['stockItemId'])
			|| !is_string($body['productId'])
			|| !is_int($body['initialQuantity'])
		) {
			throw new InvalidArgumentException('The initial stock registered event payload is invalid.');
		}

		if ($body['stockItemId'] !== $aggregateId) {
			throw new InvalidArgumentException('The aggregate ID must match the stock item ID.');
		}

		return new self(
			new StockItemId($aggregateId),
			$body['productId'],
			$body['initialQuantity'],
			$eventId,
			$occurredOn
		);
	}

	public function toPrimitives(): array
	{
		return [
			'stockItemId' => $this->stockItemId->value(),
			'productId' => $this->productId->value(),
			'initialQuantity' => $this->initialQuantity->value(),
		];
	}
}
