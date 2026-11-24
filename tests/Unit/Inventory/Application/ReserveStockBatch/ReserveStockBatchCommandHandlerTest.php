<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\ReserveStockBatch;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReserveStockBatch\Exception\DuplicatedProductInReservationBatchException;
use Manager\Inventory\Application\ReserveStockBatch\Exception\EmptyReservationBatchException;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockBatchCommand;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockBatchCommandHandler;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockLine;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Domain\UuidGenerator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Shared\Domain\SequenceUuidGenerator;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Application\Support\CountingTransactionalSession;
use Tests\Unit\Inventory\Domain\ObjectMother\OrderIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ProductIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\ReservationIdMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class ReserveStockBatchCommandHandlerTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_reserve_multiple_products_in_one_transaction(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$secondStockItem = $this->stockItem($secondProduct, 20);
		$firstReservationId = ReservationIdMother::create();
		$secondReservationId = ReservationIdMother::create();
		$command = new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
			new ReserveStockLine($firstProduct->value(), 3),
			new ReserveStockLine($secondProduct->value(), 5),
		]);
		$outbox = new CollectingOutboxRecorder();
		$session = new CountingTransactionalSession();
		$reservationIds = [$firstReservationId->value(), $secondReservationId->value()];
		$uuidGenerator = new SequenceUuidGenerator($reservationIds);

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static fn (ProductId $productId): ?StockItem => match ($productId->value()) {
				$firstProduct->value() => $firstStockItem,
				$secondProduct->value() => $secondStockItem,
				default => null,
			});

		$saved = [];
		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')
			->twice()
			->andReturnUsing(static function (StockItem $stockItem) use (&$saved): void {
				$saved[] = $stockItem;
			});

		$this->handler($reader, $repository, $uuidGenerator, $outbox, $session)($command);

		self::assertSame(1, $session->calls);
		self::assertCount(2, $saved);
		self::assertSame(3, $firstStockItem->reserved()->value());
		self::assertSame(5, $secondStockItem->reserved()->value());
		self::assertCount(1, $firstStockItem->reservations());
		self::assertCount(1, $secondStockItem->reservations());
		self::assertSame($firstReservationId->value(), $firstStockItem->reservations()[0]->id()->value());
		self::assertSame($secondReservationId->value(), $secondStockItem->reservations()[0]->id()->value());
		self::assertCount(2, $outbox->messages());
	}

	#[Test]
	public function it_should_sort_lines_by_product_identifier_before_reading_locked_stock(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$secondStockItem = $this->stockItem($secondProduct, 10);
		$firstReservationId = ReservationIdMother::create();
		$secondReservationId = ReservationIdMother::create();
		$command = new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
			new ReserveStockLine($secondProduct->value(), 4),
			new ReserveStockLine($firstProduct->value(), 2),
		]);
		$outbox = new CollectingOutboxRecorder();
		$session = new CountingTransactionalSession();
		$reservationIds = [$firstReservationId->value(), $secondReservationId->value()];
		$uuidGenerator = new SequenceUuidGenerator($reservationIds);

		$readProducts = [];
		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static function (ProductId $productId) use (
				&$readProducts,
				$firstProduct,
				$firstStockItem,
				$secondProduct,
				$secondStockItem
			): ?StockItem {
				$readProducts[] = $productId->value();

				return match ($productId->value()) {
					$firstProduct->value() => $firstStockItem,
					$secondProduct->value() => $secondStockItem,
					default => null,
				};
			});

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')->twice();

		$this->handler($reader, $repository, $uuidGenerator, $outbox, $session)($command);

		self::assertSame([$firstProduct->value(), $secondProduct->value()], $readProducts);
		self::assertSame($firstReservationId->value(), $firstStockItem->reservations()[0]->id()->value());
		self::assertSame($secondReservationId->value(), $secondStockItem->reservations()[0]->id()->value());
	}

	#[Test]
	public function it_should_reject_an_empty_request_before_accessing_persistence(): void
	{
		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$this->expectException(EmptyReservationBatchException::class);

		$this->handler(
			$reader,
			$repository,
			$uuidGenerator,
			new CollectingOutboxRecorder(),
			new CountingTransactionalSession()
		)(new ReserveStockBatchCommand(OrderIdMother::create()->value(), []));
	}

	#[Test]
	public function it_should_reject_duplicate_products_before_accessing_persistence(): void
	{
		$productId = ProductIdMother::create();

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldNotReceive('readByProductIdForUpdate');

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$uuidGenerator = $this->mockClass(UuidGenerator::class);
		$uuidGenerator->shouldNotReceive('generate');

		$this->expectException(DuplicatedProductInReservationBatchException::class);

		$this->handler(
			$reader,
			$repository,
			$uuidGenerator,
			new CollectingOutboxRecorder(),
			new CountingTransactionalSession()
		)(new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
			new ReserveStockLine($productId->value(), 1),
			new ReserveStockLine($productId->value(), 2),
		]));
	}

	#[Test]
	public function it_should_reject_an_unknown_product_and_leave_no_saves(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$uuidGenerator = new SequenceUuidGenerator([
			ReservationIdMother::create()->value(),
			ReservationIdMother::create()->value(),
		]);

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static fn (ProductId $productId): ?StockItem => match ($productId->value()) {
				$firstProduct->value() => $firstStockItem,
				default => null,
			});

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$outbox = new CollectingOutboxRecorder();
		$session = new CountingTransactionalSession();

		try {
			$this->handler($reader, $repository, $uuidGenerator, $outbox, $session)(
				new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
					new ReserveStockLine($firstProduct->value(), 2),
					new ReserveStockLine($secondProduct->value(), 3),
				])
			);
			self::fail('The missing stock item was expected to fail the batch.');
		} catch (StockItemNotFoundException) {
			// Expected.
		}

		self::assertSame(1, $session->calls);
		self::assertSame(2, $uuidGenerator->calls());
		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_reject_insufficient_stock_in_any_line_and_leave_no_saves(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$secondStockItem = $this->stockItem($secondProduct, 1);
		$uuidGenerator = new SequenceUuidGenerator([
			ReservationIdMother::create()->value(),
			ReservationIdMother::create()->value(),
		]);

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static fn (ProductId $productId): ?StockItem => match ($productId->value()) {
				$firstProduct->value() => $firstStockItem,
				$secondProduct->value() => $secondStockItem,
				default => null,
			});

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldNotReceive('save');

		$outbox = new CollectingOutboxRecorder();
		$session = new CountingTransactionalSession();

		try {
			$this->handler($reader, $repository, $uuidGenerator, $outbox, $session)(
				new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
					new ReserveStockLine($firstProduct->value(), 4),
					new ReserveStockLine($secondProduct->value(), 5),
				])
			);
			self::fail('The insufficient stock was expected to fail the batch.');
		} catch (InsufficientStockException) {
			// Expected.
		}

		self::assertSame(1, $session->calls);
		self::assertSame(4, $firstStockItem->reserved()->value());
		self::assertSame(0, $secondStockItem->reserved()->value());
		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_generate_every_reservation_identifier_before_the_transaction(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$secondStockItem = $this->stockItem($secondProduct, 10);
		$uuidGenerator = new SequenceUuidGenerator([
			ReservationIdMother::create()->value(),
			ReservationIdMother::create()->value(),
		]);

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static function (ProductId $productId) use (
				$uuidGenerator,
				$firstProduct,
				$firstStockItem,
				$secondProduct,
				$secondStockItem
			): ?StockItem {
				self::assertSame(2, $uuidGenerator->calls());

				return match ($productId->value()) {
					$firstProduct->value() => $firstStockItem,
					$secondProduct->value() => $secondStockItem,
					default => null,
				};
			});

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')->twice();

		$session = new CountingTransactionalSession();

		$this->handler($reader, $repository, $uuidGenerator, new CollectingOutboxRecorder(), $session)(
			new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
				new ReserveStockLine($firstProduct->value(), 2),
				new ReserveStockLine($secondProduct->value(), 3),
			])
		);

		self::assertSame(2, $uuidGenerator->calls());
		self::assertSame(1, $session->calls);
	}

	#[Test]
	public function it_should_return_every_reserved_stock_item_so_the_coordinator_records_their_events(): void
	{
		$firstProduct = $this->productId('00000000-0000-4000-8000-000000000001');
		$secondProduct = $this->productId('00000000-0000-4000-8000-000000000002');
		$firstStockItem = $this->stockItem($firstProduct, 10);
		$secondStockItem = $this->stockItem($secondProduct, 10);
		$uuidGenerator = new SequenceUuidGenerator([
			ReservationIdMother::create()->value(),
			ReservationIdMother::create()->value(),
		]);

		$reader = $this->mockClass(StockItemExclusiveReader::class);
		$reader->shouldReceive('readByProductIdForUpdate')
			->twice()
			->andReturnUsing(static fn (ProductId $productId): ?StockItem => match ($productId->value()) {
				$firstProduct->value() => $firstStockItem,
				$secondProduct->value() => $secondStockItem,
				default => null,
			});

		$repository = $this->mockClass(StockItemRepository::class);
		$repository->shouldReceive('save')->twice();

		$outbox = new CollectingOutboxRecorder();

		$this->handler(
			$reader,
			$repository,
			$uuidGenerator,
			$outbox,
			new CountingTransactionalSession()
		)(new ReserveStockBatchCommand(OrderIdMother::create()->value(), [
			new ReserveStockLine($firstProduct->value(), 2),
			new ReserveStockLine($secondProduct->value(), 3),
		]));

		$aggregateIds = array_map(
			static fn (OutboxMessage $message): string => $message->aggregateId(),
			$outbox->messages()
		);
		sort($aggregateIds);
		$expectedAggregateIds = [$firstStockItem->id()->value(), $secondStockItem->id()->value()];
		sort($expectedAggregateIds);

		self::assertCount(2, $outbox->messages());
		self::assertSame($expectedAggregateIds, $aggregateIds);
	}

	#[Test]
	public function it_should_return_no_synchronous_result(): void
	{
		$returnType = (new ReflectionMethod(ReserveStockBatchCommandHandler::class, '__invoke'))->getReturnType();

		self::assertInstanceOf(ReflectionNamedType::class, $returnType);
		self::assertSame('void', $returnType->getName());
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		UuidGenerator $uuidGenerator,
		CollectingOutboxRecorder $outbox,
		CountingTransactionalSession $session
	): ReserveStockBatchCommandHandler {
		return new ReserveStockBatchCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				$session,
				new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
			),
			$uuidGenerator
		);
	}

	private function stockItem(ProductId $productId, int $onHand): StockItem
	{
		$stockItem = StockItemMother::create(productId: $productId, initialQuantity: QuantityMother::create($onHand));
		$stockItem->pullDomainEvents();

		return $stockItem;
	}

	private function productId(string $value): ProductId
	{
		return ProductIdMother::create($value);
	}
}
