<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStockBatch;

use Manager\Inventory\Application\ReserveStockBatch\Exception\DuplicatedProductInReservationBatchException;
use Manager\Inventory\Application\ReserveStockBatch\Exception\EmptyReservationBatchException;
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

final readonly class ReserveStockBatchCommandHandler implements CommandHandler
{
	public function __construct(
		private StockItemExclusiveReader $reader,
		private StockItemRepository $repository,
		private StockItemTransaction $transaction,
		private UuidGenerator $uuidGenerator
	) {}

	public function __invoke(ReserveStockBatchCommand $command): void
	{
		if ($command->lines === []) {
			throw new EmptyReservationBatchException();
		}

		$orderId = new OrderId($command->orderId);
		$lines = $this->prepareLines($command->lines);

		$this->transaction->execute(function () use ($orderId, $lines): array {
			$stockItems = [];

			foreach ($lines as $line) {
				$stockItem = $this->reader->readByProductIdForUpdate($line['productId']);

				if ($stockItem === null) {
					throw new StockItemNotFoundException($line['productId']);
				}

				$stockItems[] = $stockItem;
			}

			foreach ($stockItems as $index => $stockItem) {
				$stockItem->reserve($lines[$index]['reservationId'], $orderId, $lines[$index]['quantity']);
			}

			foreach ($stockItems as $stockItem) {
				$this->repository->save($stockItem);
			}

			return $stockItems;
		});
	}

	/**
	 * @param list<ReserveStockLine> $lines
	 * @return list<array{productId: ProductId, quantity: Quantity, reservationId: ReservationId}>
	 */
	private function prepareLines(array $lines): array
	{
		/** @var list<array{productId: ProductId, quantity: Quantity}> $validated */
		$validated = [];
		/** @var array<string, true> $products */
		$products = [];

		foreach ($lines as $line) {
			$productId = new ProductId($line->productId);
			$quantity = new Quantity($line->quantity);

			if (!$quantity->isPositive()) {
				throw new QuantityMustBePositiveException($quantity->value());
			}

			if (isset($products[$productId->value()])) {
				throw new DuplicatedProductInReservationBatchException($productId);
			}

			$products[$productId->value()] = true;
			$validated[] = ['productId' => $productId, 'quantity' => $quantity];
		}

		usort(
			$validated,
			static fn (array $left, array $right): int => $left['productId']->value() <=> $right['productId']->value()
		);

		/** @var list<array{productId: ProductId, quantity: Quantity, reservationId: ReservationId}> $prepared */
		$prepared = [];

		foreach ($validated as $line) {
			$prepared[] = [
				'productId' => $line['productId'],
				'quantity' => $line['quantity'],
				'reservationId' => new ReservationId($this->uuidGenerator->generate()),
			];
		}

		return $prepared;
	}
}
