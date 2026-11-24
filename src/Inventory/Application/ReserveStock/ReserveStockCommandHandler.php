<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStock;

use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Shared\Domain\Bus\Command\CommandHandler;
use Manager\Shared\Domain\UuidGenerator;

final readonly class ReserveStockCommandHandler implements CommandHandler
{
	public function __construct(
		private StockItemExclusiveReader $reader,
		private StockItemRepository $repository,
		private StockItemTransaction $transaction,
		private UuidGenerator $uuidGenerator
	) {}

	public function __invoke(ReserveStockCommand $command): void
	{
		$productId = new ProductId($command->productId());
		$orderId = new OrderId($command->orderId());
		$quantity = new Quantity($command->quantity());

		if (!$quantity->isPositive()) {
			throw new QuantityMustBePositiveException($quantity->value());
		}

		$reservationId = new ReservationId($this->uuidGenerator->generate());

		$this->transaction->execute(function () use ($productId, $orderId, $quantity, $reservationId): array {
			$stockItem = $this->reader->readByProductIdForUpdate($productId);

			if ($stockItem === null) {
				throw new StockItemNotFoundException($productId);
			}

			$stockItem->reserve($reservationId, $orderId, $quantity);

			$this->repository->save($stockItem);

			return [$stockItem];
		});
	}
}
