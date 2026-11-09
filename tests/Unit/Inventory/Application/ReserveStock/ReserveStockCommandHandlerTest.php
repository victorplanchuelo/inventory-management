<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\ReserveStock;

use Manager\Inventory\Application\ReserveStock\ReserveStockCommand;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler;
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
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class ReserveStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_reserve_stock_for_an_existing_stock_item(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$reservationId = ReservationIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(30));
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 10);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn($stockItem);
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $savedStockItem) use ($stockItem, $orderId, $reservationId): bool {
				return $savedStockItem === $stockItem
					&& $savedStockItem->reserved()->value() === 10
					&& count($savedStockItem->reservations()) === 1
					&& $reservationId->equals($savedStockItem->reservations()[0]->id())
					&& $orderId->equals($savedStockItem->reservations()[0]->orderId());
			}));

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		$handler = new ReserveStockCommandHandler($repository, $uuidGenerator);
		$handler($command);
	}

	#[Test]
	public function it_should_reject_an_unknown_product_id(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 10);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$handler = new ReserveStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(StockItemNotFoundException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_reject_insufficient_stock(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(10));
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 30);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturn($stockItem);
		$repository->shouldNotReceive('save');

		$reservationId = ReservationIdMother::create();
		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		$handler = new ReserveStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(InsufficientStockException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_a_quantity_must_be_positive_error_for_zero_input(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create(30));
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), 0);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturn($stockItem);
		$repository->shouldNotReceive('save');

		$reservationId = ReservationIdMother::create();
		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->withNoArgs()->andReturn($reservationId->value());

		$handler = new ReserveStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(QuantityMustBePositiveException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_quantity_error_for_negative_input(): void
	{
		$productId = ProductIdMother::create();
		$orderId = OrderIdMother::create();
		$command = new ReserveStockCommand($productId->value(), $orderId->value(), -1);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('searchByProductId');
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$handler = new ReserveStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(InvalidQuantityException::class);

		$handler($command);
	}
}
