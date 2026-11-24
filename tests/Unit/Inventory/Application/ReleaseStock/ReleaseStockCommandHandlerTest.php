<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\ReleaseStock;

use InvalidArgumentException;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommand;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException;
use Manager\Inventory\Domain\Exception\ReservationNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Application\Support\ImmediateTransactionalSession;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class ReleaseStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_release_a_reservation_through_the_exclusive_reader_and_record_the_event(): void
	{
		$stockItem = StockItemMother::withActiveReservation(initialQuantity: 30, reservationQuantity: 10);
		$reservationId = $stockItem->reservations()[0]->id();
		$command = new ReleaseStockCommand($reservationId->value());
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByReservationIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $savedStockItem) use ($stockItem, $reservationId): bool {
				return $savedStockItem === $stockItem
					&& $savedStockItem->reserved()->value() === 0
					&& count($savedStockItem->reservations()) === 1
					&& $reservationId->equals($savedStockItem->reservations()[0]->id())
					&& $savedStockItem->reservations()[0]->isReleased();
			}));

		$this->handler($reader, $repository, $outbox)($command);

		$messages = $outbox->messages();

		self::assertCount(1, $messages);
		self::assertSame('inventory.stock_released', $messages[0]->eventName());
		self::assertSame($stockItem->id()->value(), $messages[0]->aggregateId());
	}

	#[Test]
	public function it_should_reject_an_unknown_reservation_without_saving(): void
	{
		$reservationId = ReservationIdMother::create();
		$command = new ReleaseStockCommand($reservationId->value());
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByReservationIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturnNull();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		try {
			$this->handler($reader, $repository, $outbox)($command);
			self::fail('The missing reservation was expected to fail the release.');
		} catch (ReservationNotFoundException) {
			// Expected.
		}

		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_propagate_an_already_released_reservation(): void
	{
		$stockItem = StockItemMother::withReleasedReservation(initialQuantity: 30, reservationQuantity: 10);
		$reservationId = $stockItem->reservations()[0]->id();
		$command = new ReleaseStockCommand($reservationId->value());
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByReservationIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ReservationId $received): bool => $received->equals($reservationId)))
			->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		try {
			$this->handler($reader, $repository, $outbox)($command);
			self::fail('The already released reservation was expected to fail the release.');
		} catch (ReservationAlreadyReleasedException) {
			// Expected.
		}

		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_propagate_an_invalid_reservation_id_before_accessing_persistence(): void
	{
		$command = new ReleaseStockCommand('not-a-uuid');
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByReservationIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$this->expectException(InvalidArgumentException::class);

		$this->handler($reader, $repository, $outbox)($command);
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		CollectingOutboxRecorder $outbox
	): ReleaseStockCommandHandler {
		return new ReleaseStockCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				new ImmediateTransactionalSession(),
				new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
			)
		);
	}
}
