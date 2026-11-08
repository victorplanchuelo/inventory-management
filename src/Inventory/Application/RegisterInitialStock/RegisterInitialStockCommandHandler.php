<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\RegisterInitialStock;

use Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Domain\Bus\Command\CommandHandler;
use Manager\Shared\Domain\UuidGenerator;

final readonly class RegisterInitialStockCommandHandler implements CommandHandler
{
	public function __construct(
		private StockItemRepository $repository,
		private UuidGenerator $uuidGenerator
	) {}

	public function __invoke(RegisterInitialStockCommand $command): void
	{
		$productId = new ProductId($command->productId());
		$initialQuantity = new Quantity($command->initialQuantity());

		if ($this->repository->searchByProductId($productId) !== null) {
			throw new StockItemAlreadyExistsException($productId);
		}

		$stockItem = StockItem::register(
			new StockItemId($this->uuidGenerator->generate()),
			$productId,
			$initialQuantity
		);

		$this->repository->save($stockItem);
	}
}
