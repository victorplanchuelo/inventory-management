<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\AddStock;

use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Shared\Domain\Bus\Command\CommandHandler;

final readonly class AddStockCommandHandler implements CommandHandler
{
	public function __construct(
		private StockItemExclusiveReader $reader,
		private StockItemRepository $repository,
		private StockItemTransaction $transaction
	) {}

	public function __invoke(AddStockCommand $command): void
	{
		$productId = new ProductId($command->productId());
		$quantity = new Quantity($command->quantity());

		if (!$quantity->isPositive()) {
			throw new QuantityMustBePositiveException($quantity->value());
		}

		$this->transaction->execute(function () use ($productId, $quantity): array {
			$stockItem = $this->reader->readByProductIdForUpdate($productId);

			if ($stockItem === null) {
				throw new StockItemNotFoundException($productId);
			}

			$stockItem->addStock($quantity);

			$this->repository->save($stockItem);

			return [$stockItem];
		});
	}
}
