<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\AddStock;

use Manager\Inventory\Application\AddStock\AddStockCommand;
use Manager\Inventory\Application\AddStock\AddStockCommandHandler;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class AddStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_add_stock_to_an_existing_stock_item(): void
	{
		$productId = ProductIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$command = new AddStockCommand($productId->value(), 5);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn($stockItem);
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $savedStockItem) use ($stockItem): bool {
				return $savedStockItem === $stockItem
					&& $savedStockItem->onHand()->value() === 15;
			}));

		$handler = new AddStockCommandHandler($repository);
		$handler($command);
	}

	#[Test]
	public function it_should_reject_an_unknown_product_id(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), 5);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();
		$repository->shouldNotReceive('save');

		$handler = new AddStockCommandHandler($repository);

		$this->expectException(StockItemNotFoundException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_a_quantity_must_be_positive_error_for_zero_input(): void
	{
		$productId = ProductIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$command = new AddStockCommand($productId->value(), 0);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturn($stockItem);
		$repository->shouldNotReceive('save');

		$handler = new AddStockCommandHandler($repository);

		$this->expectException(QuantityMustBePositiveException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_quantity_error_for_negative_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), -1);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('searchByProductId');
		$repository->shouldNotReceive('save');

		$handler = new AddStockCommandHandler($repository);

		$this->expectException(InvalidQuantityException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_persist_the_updated_aggregate(): void
	{
		$productId = ProductIdMother::create();
		$command = new AddStockCommand($productId->value(), 5);
		$savedStockItem = null;

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->andReturn(StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10)));
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $stockItem) use (&$savedStockItem): bool {
				$savedStockItem = $stockItem;

				return true;
			}));

		$handler = new AddStockCommandHandler($repository);
		$handler($command);

		self::assertInstanceOf(StockItem::class, $savedStockItem);
		self::assertSame($productId->value(), $savedStockItem->productId()->value());
		self::assertSame(15, $savedStockItem->onHand()->value());
	}
}
