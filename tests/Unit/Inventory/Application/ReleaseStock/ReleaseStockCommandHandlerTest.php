<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\ReleaseStock;

use InvalidArgumentException;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommand;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler;
use Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException;
use Manager\Inventory\Domain\Exception\ReservationNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class ReleaseStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_release_a_reservation_using_only_its_reservation_id(): void
	{
		$stockItem = StockItemMother::withActiveReservation(initialQuantity: 30, reservationQuantity: 10);
		$reservationId = $stockItem->reservations()[0]->id();
		$command = new ReleaseStockCommand($reservationId->value());

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByReservationId')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturn($stockItem);
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $savedStockItem) use ($stockItem, $reservationId): bool {
				return $savedStockItem === $stockItem
					&& $savedStockItem->reserved()->value() === 0
					&& count($savedStockItem->reservations()) === 1
					&& $reservationId->equals($savedStockItem->reservations()[0]->id())
					&& $savedStockItem->reservations()[0]->isReleased();
			}));

		$handler = new ReleaseStockCommandHandler($repository);
		$handler($command);
	}

	#[Test]
	public function it_should_reject_an_unknown_reservation(): void
	{
		$reservationId = ReservationIdMother::create();
		$command = new ReleaseStockCommand($reservationId->value());

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByReservationId')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturnNull();
		$repository->shouldNotReceive('save');

		$handler = new ReleaseStockCommandHandler($repository);

		$this->expectException(ReservationNotFoundException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_reject_an_already_released_reservation(): void
	{
		$stockItem = StockItemMother::withReleasedReservation(initialQuantity: 30, reservationQuantity: 10);
		$reservationId = $stockItem->reservations()[0]->id();
		$command = new ReleaseStockCommand($reservationId->value());

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByReservationId')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturn($stockItem);
		$repository->shouldNotReceive('save');

		$handler = new ReleaseStockCommandHandler($repository);

		$this->expectException(ReservationAlreadyReleasedException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_reservation_id(): void
	{
		$command = new ReleaseStockCommand('not-a-uuid');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('searchByReservationId');
		$repository->shouldNotReceive('save');

		$handler = new ReleaseStockCommandHandler($repository);

		$this->expectException(InvalidArgumentException::class);

		$handler($command);
	}
}
