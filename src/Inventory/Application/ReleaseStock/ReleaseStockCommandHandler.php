<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReleaseStock;

use Manager\Inventory\Domain\Exception\ReservationNotFoundException;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Shared\Domain\Bus\Command\CommandHandler;

final readonly class ReleaseStockCommandHandler implements CommandHandler
{
	public function __construct(private StockItemRepository $repository) {}

	public function __invoke(ReleaseStockCommand $command): void
	{
		$reservationId = new ReservationId($command->reservationId());

		$stockItem = $this->repository->searchByReservationId($reservationId);

		if ($stockItem === null) {
			throw new ReservationNotFoundException($reservationId);
		}

		$stockItem->releaseReservation($reservationId);

		$this->repository->save($stockItem);
	}
}
