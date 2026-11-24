<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\ReserveStock;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommand;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Domain\UuidGenerator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Application\Support\ImmediateTransactionalSession;
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class ReserveStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_reserve_stock_through_the_exclusive_reader_and_record_the_event(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(30));
		$stockItem->pullDomainEvents();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 10);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByProductIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $savedStockItem) use ($stockItem, $orderId, $reservationId): bool {
				return $savedStockItem === $stockItem
					&& $savedStockItem->reserved()->value() === 10
					&& count($savedStockItem->reservations()) === 1
					&& $reservationId->equals($savedStockItem->reservations()[0]->id())
					&& $orderId->equals($savedStockItem->reservations()[0]->orderId())
					&& $savedStockItem->reservations()[0]->isActive();
			}));

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);

		$messages = $outbox->messages();

		self::assertCount(1, $messages);
		self::assertSame('inventory.stock_reserved', $messages[0]->eventName());
		self::assertSame($stockItem->id()->value(), $messages[0]->aggregateId());
	}

	#[Test]
	public function it_should_generate_the_reservation_identifier_before_the_transaction(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(30));
		$stockItem->pullDomainEvents();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 10);
		$outbox = new CollectingOutboxRecorder();
		$uuidGenerator = new class() implements UuidGenerator {
			public bool $generated = false;

			public function generate(): string
			{
				$this->generated = true;

				return ReservationIdMother::create()->value();
			}
		};

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByProductIdForUpdate')
			->once()
			->with(Mockery::on(static function (ProductId $received) use ($productId, $uuidGenerator): bool {
				return $received->equals($productId) && $uuidGenerator->generated;
			}))
			->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'save')->once();

		$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);

		self::assertTrue($uuidGenerator->generated);
	}

	#[Test]
	public function it_should_reject_an_unknown_product_without_saving(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 10);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByProductIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		try {
			$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);
			self::fail('The missing stock item was expected to fail the reservation.');
		} catch (StockItemNotFoundException) {
			// Expected.
		}

		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_reject_insufficient_stock_without_saving_or_recording_events(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$stockItem->pullDomainEvents();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 30);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$this->expectation($reader, 'readByProductIdForUpdate')->once()->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		try {
			$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);
			self::fail('The insufficient stock was expected to fail the reservation.');
		} catch (InsufficientStockException) {
			// Expected.
		}

		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_reject_a_zero_quantity_without_accessing_persistence(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 0);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$this->expectException(QuantityMustBePositiveException::class);

		$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);
	}

	#[Test]
	public function it_should_reject_a_negative_quantity_before_accessing_persistence(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), -1);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$this->expectException(InvalidQuantityException::class);

		$this->handler($reader, $repository, $uuidGenerator, $outbox)($command);
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		UuidGenerator $uuidGenerator,
		CollectingOutboxRecorder $outbox
	): ReserveStockCommandHandler {
		return new ReserveStockCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				new ImmediateTransactionalSession(),
				new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
			),
			$uuidGenerator
		);
	}
}
