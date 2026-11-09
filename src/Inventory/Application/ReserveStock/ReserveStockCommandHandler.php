<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStock;

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
		private StockItemRepository $repository,
		private UuidGenerator $uuidGenerator
	) {}

	public function __invoke(ReserveStockCommand $command): void
	{
		$productId = new ProductId($command->productId());
		$orderId = new OrderId($command->orderId());
		$quantity = new Quantity($command->quantity());

		$stockItem = $this->repository->searchByProductId($productId);

		if ($stockItem === null) {
			throw new StockItemNotFoundException($productId);
		}

		$reservationId = new ReservationId($this->uuidGenerator->generate());

		$stockItem->reserve($reservationId, $orderId, $quantity);

		$this->repository->save($stockItem);
	}
}
