<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application;

use Illuminate\Database\QueryException;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockBatchCommand;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockBatchCommandHandler;
use Manager\Inventory\Application\ReserveStockBatch\ReserveStockLine;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Domain\UuidGenerator;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Inventory\Application\Support\AlwaysFailingStockItemRepository;
use Tests\Integration\Inventory\Application\Support\RetryingStockItemRepository;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\SequenceUuidGenerator;
use Tests\Shared\Domain\UuidMother;

final class StockBatchRetryTest extends InventoryIntegrationTestCase
{
	private MySqlStockItemRepository $repository;
	private OutboxRecorder $outboxRecorder;

	/** @var list<string> */
	private array $aggregateIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->aggregateIds = [];
		$this->repository = app(MySqlStockItemRepository::class);
		$this->outboxRecorder = app(OutboxRecorder::class);
	}

	protected function tearDown(): void
	{
		if ($this->aggregateIds !== []) {
			$eventIds = $this->connection->table('outbox')
				->whereIn('aggregate_id', $this->aggregateIds)
				->pluck('event_id')
				->all();

			foreach ($eventIds as $eventId) {
				if (is_string($eventId)) {
					$this->trackOutboxEvent($eventId);
				}
			}
		}

		parent::tearDown();
	}

	#[Test]
	public function it_should_retry_a_batch_and_apply_it_exactly_once_with_the_pre_generated_identifiers(): void
	{
		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$firstReservation = UuidMother::create();
		$secondReservation = UuidMother::create();
		$orderId = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 20);
		$retryingRepository = new RetryingStockItemRepository($this->repository);
		$uuidGenerator = new SequenceUuidGenerator([$firstReservation, $secondReservation]);

		$this->handler($retryingRepository, $retryingRepository, $uuidGenerator)(
			new ReserveStockBatchCommand($orderId, [
				new ReserveStockLine($firstProduct, 3),
				new ReserveStockLine($secondProduct, 5),
			])
		);

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->whereIn('stock_item_id', [$firstStockItem, $secondStockItem])
			->get()
			->all();
		$reservationIds = array_map(static fn (object $row): string => (string) $row->id, $reservationRows);
		sort($reservationIds);
		$expectedReservationIds = [$firstReservation, $secondReservation];
		sort($expectedReservationIds);

		self::assertSame(2, $uuidGenerator->calls());
		self::assertCount(4, $retryingRepository->readStockItems);
		self::assertNotSame($retryingRepository->readStockItems[0], $retryingRepository->readStockItems[2]);
		self::assertNotSame($retryingRepository->readStockItems[1], $retryingRepository->readStockItems[3]);
		self::assertSame([10, 20, 10, 20], $retryingRepository->readOnHand);
		self::assertCount(3, $retryingRepository->savedStockItems);
		self::assertSame([10, 10, 20], $retryingRepository->savedOnHand);
		self::assertNotNull($firstRow);
		self::assertSame(3, (int) $firstRow->reserved);
		self::assertNotNull($secondRow);
		self::assertSame(5, (int) $secondRow->reserved);
		self::assertCount(2, $reservationRows);
		self::assertSame($expectedReservationIds, $reservationIds);
		self::assertCount(1, $this->outboxRows($firstStockItem));
		self::assertCount(1, $this->outboxRows($secondStockItem));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_stop_after_three_attempts_and_leave_no_partial_state(): void
	{
		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$firstReservation = UuidMother::create();
		$secondReservation = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 20);
		$failingRepository = new AlwaysFailingStockItemRepository($this->repository);
		$uuidGenerator = new SequenceUuidGenerator([$firstReservation, $secondReservation]);

		try {
			$this->handler($failingRepository, $failingRepository, $uuidGenerator)(
				new ReserveStockBatchCommand(UuidMother::create(), [
					new ReserveStockLine($firstProduct, 3),
					new ReserveStockLine($secondProduct, 5),
				])
			);
			self::fail('The deadlock failure was expected to exhaust the retries.');
		} catch (QueryException) {
			// Expected.
		}

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();

		self::assertSame(2, $uuidGenerator->calls());
		self::assertCount(6, $failingRepository->readStockItems);
		self::assertCount(3, $failingRepository->savedStockItems);
		self::assertNotNull($firstRow);
		self::assertSame(0, (int) $firstRow->reserved);
		self::assertNotNull($secondRow);
		self::assertSame(0, (int) $secondRow->reserved);
		self::assertSame(
			0,
			$this->connection->table('inventory_stock_reservations')
				->whereIn('stock_item_id', [$firstStockItem, $secondStockItem])
				->count()
		);
		self::assertSame([], $this->outboxRows($firstStockItem));
		self::assertSame([], $this->outboxRows($secondStockItem));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_not_retry_a_business_validation_failure_in_a_batch(): void
	{
		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 1);
		$retryingRepository = new RetryingStockItemRepository($this->repository);
		$uuidGenerator = new SequenceUuidGenerator([UuidMother::create(), UuidMother::create()]);

		try {
			$this->handler($retryingRepository, $retryingRepository, $uuidGenerator)(
				new ReserveStockBatchCommand(UuidMother::create(), [
					new ReserveStockLine($firstProduct, 4),
					new ReserveStockLine($secondProduct, 5),
				])
			);
			self::fail('The insufficient stock was expected to fail the batch.');
		} catch (InsufficientStockException) {
			// Expected.
		}

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();

		self::assertSame(2, $uuidGenerator->calls());
		self::assertCount(2, $retryingRepository->readStockItems);
		self::assertSame([], $retryingRepository->savedStockItems);
		self::assertNotNull($firstRow);
		self::assertSame(0, (int) $firstRow->reserved);
		self::assertNotNull($secondRow);
		self::assertSame(0, (int) $secondRow->reserved);
		self::assertSame(
			0,
			$this->connection->table('inventory_stock_reservations')
				->whereIn('stock_item_id', [$firstStockItem, $secondStockItem])
				->count()
		);
		self::assertSame([], $this->outboxRows($firstStockItem));
		self::assertSame([], $this->outboxRows($secondStockItem));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		UuidGenerator $uuidGenerator
	): ReserveStockBatchCommandHandler {
		return new ReserveStockBatchCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				new MySqlTransactionalSession($this->connection),
				new StockItemIntegrationEventRecorder($this->outboxRecorder, new StockItemIntegrationEventMapper())
			),
			$uuidGenerator
		);
	}

	private function persistStockItem(string $stockItemId, string $productId, int $onHand): void
	{
		$this->trackStockItem($stockItemId);
		$this->aggregateIds[] = $stockItemId;

		$stockItem = StockItem::register(
			new StockItemId($stockItemId),
			new ProductId($productId),
			new Quantity($onHand)
		);
		$stockItem->pullDomainEvents();

		$this->repository->save($stockItem);
	}

	/**
	 * @return list<object>
	 */
	private function outboxRows(string $aggregateId): array
	{
		return $this->connection->table('outbox')
			->where('aggregate_id', $aggregateId)
			->orderBy('created_at')
			->orderBy('event_name')
			->get()
			->all();
	}
}
