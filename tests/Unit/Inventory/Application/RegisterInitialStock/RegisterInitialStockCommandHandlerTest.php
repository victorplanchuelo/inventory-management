<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\RegisterInitialStock;

use LogicException;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommand;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandler;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Domain\UuidGenerator;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class RegisterInitialStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_register_stock_when_the_product_does_not_exist(): void
	{
		$productId = ProductIdMother::create();
		$stockItemId = StockItemIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 20);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $stockItem) use ($stockItemId, $productId): bool {
				return $stockItem->id()->equals($stockItemId)
					&& $stockItem->productId()->equals($productId)
					&& $stockItem->onHand()->value() === 20;
			}));

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')
			->once()
			->withNoArgs()
			->andReturn($stockItemId->value());

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);
		$handler($command);
	}

	#[Test]
	public function it_should_reject_an_already_registered_product_id(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 20);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn(StockItemMother::create(productId: $productId));
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(StockItemAlreadyExistsException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_generate_a_stock_item_id_through_the_uuid_generator(): void
	{
		$productId = ProductIdMother::create();
		$stockItemId = StockItemIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 5);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturnNull();
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static fn (StockItem $stockItem): bool => $stockItem->id()->equals($stockItemId)));

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')
			->once()
			->withNoArgs()
			->andReturn($stockItemId->value());

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);
		$handler($command);
	}

	#[Test]
	public function it_should_persist_the_registered_aggregate(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 7);
		$savedStockItem = null;

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturnNull();
		$this->expectation($repository, 'save')
			->once()
			->with(Mockery::on(static function (StockItem $stockItem) use (&$savedStockItem): bool {
				$savedStockItem = $stockItem;

				return true;
			}));

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->andReturn(StockItemIdMother::create()->value());

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);
		$handler($command);

		self::assertInstanceOf(StockItem::class, $savedStockItem);
		self::assertSame($productId->value(), $savedStockItem->productId()->value());
	}

	#[Test]
	public function it_should_propagate_a_quantity_must_be_positive_error_for_zero_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 0);

		$repository = $this->mockClass(StockItemRepository::class);
		$this->expectation($repository, 'searchByProductId')->once()->andReturnNull();
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$this->expectation($uuidGenerator, 'generate')->once()->andReturn(StockItemIdMother::create()->value());

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(QuantityMustBePositiveException::class);

		$handler($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_quantity_error_for_negative_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), -1);

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('searchByProductId');
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$handler = new RegisterInitialStockCommandHandler($repository, $uuidGenerator);

		$this->expectException(InvalidQuantityException::class);

		$handler($command);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $interface
	 * @return MockInterface&T
	 */
	private function mockClass(string $interface): MockInterface
	{
		return Mockery::mock($interface);
	}

	private function expectation(MockInterface $mock, string $method): MockeryExpectation
	{
		$expectation = $mock->shouldReceive($method);

		if (!$expectation instanceof CompositeExpectation) {
			throw new LogicException(sprintf('Unexpected Mockery expectation type <%s>.', get_debug_type($expectation)));
		}

		return new MockeryExpectation($expectation);
	}
}

final class MockeryExpectation
{
	public function __construct(private readonly CompositeExpectation $expectation) {}

	public function once(): self
	{
		$this->expectation->__call('once', []);

		return $this;
	}

	public function with(mixed ...$arguments): self
	{
		$this->expectation->__call('with', $arguments);

		return $this;
	}

	public function withNoArgs(): self
	{
		$this->expectation->__call('withNoArgs', []);

		return $this;
	}

	public function andReturn(mixed ...$values): self
	{
		$this->expectation->__call('andReturn', $values);

		return $this;
	}

	public function andReturnNull(): self
	{
		$this->expectation->__call('andReturnNull', []);

		return $this;
	}
}
