<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\RegisterInitialStock;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommand;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Domain\UuidGenerator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Application\Support\ImmediateTransactionalSession;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class RegisterInitialStockCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_register_stock_and_record_the_event_through_the_transaction_coordinator(): void
	{
		$productId = ProductIdMother::create();
		$stockItemId = StockItemIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 20);
		$outbox = new CollectingOutboxRecorder();
		$savedStockItem = null;

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturnNull();
		$repository->shouldReceive('save')
			->once()
			->with(Mockery::on(static function (StockItem $stockItem) use (&$savedStockItem): bool {
				$savedStockItem = $stockItem;

				return true;
			}));

		$this->handler($repository, $this->fixedUuidGenerator($stockItemId->value()), $outbox)($command);

		self::assertInstanceOf(StockItem::class, $savedStockItem);
		self::assertSame($stockItemId->value(), $savedStockItem->id()->value());
		self::assertSame($productId->value(), $savedStockItem->productId()->value());
		self::assertSame(20, $savedStockItem->onHand()->value());

		$messages = $outbox->messages();
		self::assertCount(1, $messages);
		self::assertSame('inventory.stock_registered', $messages[0]->eventName());
		self::assertSame($stockItemId->value(), $messages[0]->aggregateId());
	}

	#[Test]
	public function it_should_generate_the_stock_item_identifier_before_the_transaction(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 5);
		$outbox = new CollectingOutboxRecorder();
		$uuidGenerator = new class() implements UuidGenerator {
			public bool $generated = false;

			public function generate(): string
			{
				$this->generated = true;

				return StockItemIdMother::create()->value();
			}
		};

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('searchByProductId')
			->once()
			->with(Mockery::on(static function (ProductId $received) use ($productId, $uuidGenerator): bool {
				return $received->equals($productId) && $uuidGenerator->generated;
			}))
			->andReturnNull();
		$repository->shouldReceive('save')->once();

		$this->handler($repository, $uuidGenerator, $outbox)($command);

		self::assertTrue($uuidGenerator->generated);
	}

	#[Test]
	public function it_should_reject_an_already_registered_product_id(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 20);
		$outbox = new CollectingOutboxRecorder();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('searchByProductId')
			->once()
			->with(Mockery::on(static fn (ProductId $received): bool => $received->equals($productId)))
			->andReturn(StockItemMother::create(productId: $productId));
		$repository->shouldNotReceive('save');

		$this->expectException(StockItemAlreadyExistsException::class);

		$this->handler($repository, $this->fixedUuidGenerator(StockItemIdMother::create()->value()), $outbox)($command);
	}

	#[Test]
	public function it_should_propagate_repository_failures(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 20);
		$outbox = new CollectingOutboxRecorder();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('searchByProductId')
			->once()
			->andThrow(new RuntimeException('repository failed'));
		$repository->shouldNotReceive('save');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('repository failed');

		$this->handler($repository, $this->fixedUuidGenerator(StockItemIdMother::create()->value()), $outbox)($command);
	}

	#[Test]
	public function it_should_propagate_a_quantity_must_be_positive_error_for_zero_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), 0);
		$outbox = new CollectingOutboxRecorder();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('searchByProductId')->once()->andReturnNull();
		$repository->shouldNotReceive('save');

		$this->expectException(QuantityMustBePositiveException::class);

		$this->handler($repository, $this->fixedUuidGenerator(StockItemIdMother::create()->value()), $outbox)($command);
	}

	#[Test]
	public function it_should_propagate_an_invalid_quantity_error_for_negative_input(): void
	{
		$productId = ProductIdMother::create();
		$command = new RegisterInitialStockCommand($productId->value(), -1);
		$outbox = new CollectingOutboxRecorder();

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('searchByProductId');
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$this->expectException(InvalidQuantityException::class);

		$this->handler($repository, $uuidGenerator, $outbox)($command);
	}

	private function handler(
		StockItemRepository $repository,
		UuidGenerator $uuidGenerator,
		CollectingOutboxRecorder $outbox
	): RegisterInitialStockCommandHandler {
		return new RegisterInitialStockCommandHandler(
			$repository,
			new StockItemTransaction(
				new ImmediateTransactionalSession(),
				new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
			),
			$uuidGenerator
		);
	}

	private function fixedUuidGenerator(string $uuid): UuidGenerator
	{
		return new class($uuid) implements UuidGenerator {
			public function __construct(private readonly string $uuid) {}

			public function generate(): string
			{
				return $this->uuid;
			}
		};
	}
}
