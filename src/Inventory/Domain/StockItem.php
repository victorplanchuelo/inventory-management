<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain;

use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Aggregate\AggregateRoot;

final class StockItem extends AggregateRoot
{
	private function __construct(
		private readonly StockItemId $id,
		private readonly ProductId $productId,
		private Quantity $onHand,
		private Quantity $reserved
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

	public function available(): Quantity
	{
		return new Quantity($this->onHand->value() - $this->reserved->value());
	}
}
