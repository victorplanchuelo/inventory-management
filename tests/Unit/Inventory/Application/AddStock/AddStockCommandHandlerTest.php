<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\AddStock;

use Manager\Inventory\Application\AddStock\AddStockCommand;
use Manager\Inventory\Application\AddStock\AddStockCommandHandler;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Application\Support\ImmediateTransactionalSession;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class AddStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_add_stock_through_the_exclusive_reader_and_record_the_event(): void
	{
		$productId = ProductIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$stockItem->pullDomainEvents();
		$command = new AddStockCommand($productId->value(), 5);
		$outbox = new CollectingOutboxRecorder();
		$savedStockItem = null;

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')
			->once()
			->with(Mockery::on(static function (StockItem $received) use (&$savedStockItem): bool {
				$savedStockItem = $received;

				return true;
			}));

		$this->handler($reader, $repository, $outbox)($command);

		self::assertSame($stockItem, $savedStockItem);
		self::assertSame(15, $savedStockItem->onHand()->value());

		$messages = $outbox->messages();
		self::assertCount(1, $messages);
		self::assertSame('inventory.stock_added', $messages[0]->eventName());
		self::assertSame($stockItem->id()->value(), $messages[0]->aggregateId());
	}

	#[Test]
	public function it_should_reject_an_unknown_product_id(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), 5);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$this->expectException(StockItemNotFoundException::class);

		$this->handler($reader, $repository, $outbox)($command);
	}

	#[Test]
	public function it_should_reject_a_zero_quantity_without_accessing_persistence(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), 0);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$this->expectException(QuantityMustBePositiveException::class);

		$this->handler($reader, $repository, $outbox)($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_quantity_error_for_negative_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), -1);
		$outbox = new CollectingOutboxRecorder();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$this->expectException(InvalidQuantityException::class);

		$this->handler($reader, $repository, $outbox)($command);
	}

	#[Test]
	public function it_should_persist_the_updated_aggregate(): void
	{
		$productId = ProductIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$stockItem->pullDomainEvents();
		$command = new AddStockCommand($productId->value(), 5);
		$outbox = new CollectingOutboxRecorder();
		$savedStockItem = null;

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')->once()->andReturn($stockItem);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')
			->once()
			->with(Mockery::on(static function (StockItem $received) use (&$savedStockItem): bool {
				$savedStockItem = $received;

				return true;
			}));

		$this->handler($reader, $repository, $outbox)($command);

		self::assertInstanceOf(StockItem::class, $savedStockItem);
		self::assertSame($productId->value(), $savedStockItem->productId()->value());
		self::assertSame(15, $savedStockItem->onHand()->value());
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		CollectingOutboxRecorder $outbox
	): AddStockCommandHandler {
		return new AddStockCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				new ImmediateTransactionalSession(),
				new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
			)
		);
	}
}
