<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Manager\Inventory\Application\AddStock\AddStockCommand;
use Manager\Inventory\Application\AddStock\AddStockCommandHandler;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommand;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\QuantityMustBePositiveException;
use Manager\Inventory\Domain\Exception\StockItemNotFoundException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Tests\Integration\Inventory\Application\Support\FailingStockItemRepository;
use Tests\Integration\Inventory\Application\Support\FixedUuidGenerator;
use Tests\Integration\Inventory\Application\Support\RetryingStockItemRepository;
use Tests\Integration\Inventory\Application\Support\ThrowingOutboxRecorder;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;

final class StockRegistrationAndAdditionAtomicityTest extends InventoryIntegrationTestCase
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
	public function it_should_commit_stock_registration_and_its_outbox_record_together(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->trackAggregate($stockItemId);

		$this->registerHandler($this->outboxRecorder, $stockItemId)(
			new RegisterInitialStockCommand($productId, 20)
		);

		$row = $this->connection->table('inventory_stock_items')
			->where('id', $stockItemId)
			->first();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertNotNull($row);
		self::assertSame(20, (int) $row->on_hand);
		self::assertSame(0, (int) $row->reserved);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_registered', $outboxRows[0]->event_name);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_commit_stock_addition_and_its_outbox_record_together(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		$this->addHandler($this->outboxRecorder, $this->repository, $this->repository)(
			new AddStockCommand($productId, 5)
		);

		$row = $this->connection->table('inventory_stock_items')
			->where('id', $stockItemId)
			->first();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertNotNull($row);
		self::assertSame(15, (int) $row->on_hand);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_added', $outboxRows[0]->event_name);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_persist_the_stock_registered_payload_with_the_committed_state(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->trackAggregate($stockItemId);

		$this->registerHandler($this->outboxRecorder, $stockItemId)(
			new RegisterInitialStockCommand($productId, 20)
		);

		$payload = $this->outboxPayload($stockItemId);

		self::assertSame($stockItemId, $payload['stockItemId']);
		self::assertSame($productId, $payload['productId']);
		self::assertSame(20, $payload['initialQuantity']);
		self::assertSame(20, $payload['onHand']);
		self::assertSame(0, $payload['reserved']);
		self::assertSame(20, $payload['available']);
	}

	#[Test]
	public function it_should_persist_the_stock_added_payload_with_the_committed_state(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		$this->addHandler($this->outboxRecorder, $this->repository, $this->repository)(
			new AddStockCommand($productId, 5)
		);

		$payload = $this->outboxPayload($stockItemId);

		self::assertSame($stockItemId, $payload['stockItemId']);
		self::assertSame($productId, $payload['productId']);
		self::assertSame(5, $payload['addedQuantity']);
		self::assertSame(15, $payload['onHand']);
		self::assertSame(0, $payload['reserved']);
		self::assertSame(15, $payload['available']);
	}

	#[Test]
	public function it_should_roll_back_the_stock_row_when_the_outbox_write_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->trackAggregate($stockItemId);

		try {
			$this->registerHandler(new ThrowingOutboxRecorder(), $stockItemId)(
				new RegisterInitialStockCommand($productId, 20)
			);
			self::fail('The outbox failure was expected to propagate.');
		} catch (RuntimeException $exception) {
			self::assertSame('Outbox write failed.', $exception->getMessage());
		}

		self::assertSame(0, $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->count());
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_stock_and_reservations_when_a_repository_write_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId);

		try {
			$this->addHandler(
				$this->outboxRecorder,
				$this->repository,
				new FailingStockItemRepository($this->repository)
			)(new AddStockCommand($productId, 5));
			self::fail('The repository failure was expected to propagate.');
		} catch (RuntimeException $exception) {
			self::assertSame('Repository write failed.', $exception->getMessage());
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();

		self::assertNotNull($stockRow);
		self::assertSame(10, (int) $stockRow->on_hand);
		self::assertSame(3, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('active', $reservationRow->status);
		self::assertSame(3, (int) $reservationRow->quantity);
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_the_outbox_record_when_the_business_operation_fails(): void
	{
		$productId = UuidMother::create();
		$outboxCount = $this->connection->table('outbox')->count();

		try {
			$this->addHandler($this->outboxRecorder, $this->repository, $this->repository)(
				new AddStockCommand($productId, 5)
			);
			self::fail('The missing stock item was expected to fail the operation.');
		} catch (StockItemNotFoundException) {
			// Expected.
		}

		self::assertSame($outboxCount, $this->connection->table('outbox')->count());
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_leave_no_outbox_record_when_domain_validation_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->trackAggregate($stockItemId);

		try {
			$this->registerHandler($this->outboxRecorder, $stockItemId)(
				new RegisterInitialStockCommand($productId, 0)
			);
			self::fail('The non-positive initial quantity was expected to fail the operation.');
		} catch (QuantityMustBePositiveException) {
			// Expected.
		}

		self::assertSame(0, $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->count());
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_use_the_same_default_connection_for_repository_session_and_recorder(): void
	{
		$session = app(TransactionalSession::class);
		$recorder = app(OutboxRecorder::class);

		self::assertSame(DB::connection(), $this->privateConnection($this->repository));
		self::assertSame(DB::connection(), $this->privateConnection($session));
		self::assertSame(DB::connection(), $this->privateConnection($recorder));
	}

	#[Test]
	public function it_should_leave_only_pending_outbox_records_after_commit(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->trackAggregate($stockItemId);

		$this->registerHandler($this->outboxRecorder, $stockItemId)(
			new RegisterInitialStockCommand($productId, 20)
		);

		$outboxRows = $this->outboxRows($stockItemId);

		self::assertCount(1, $outboxRows);
		self::assertSame('pending', $outboxRows[0]->status);
		self::assertNull($outboxRows[0]->published_at);
		self::assertNull($outboxRows[0]->locked_at);
		self::assertNull($outboxRows[0]->failed_at);
		self::assertSame(0, (int) $outboxRows[0]->attempts);
	}

	#[Test]
	public function it_should_reload_the_aggregate_on_every_retry_and_apply_the_operation_once(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);
		$retryingRepository = new RetryingStockItemRepository($this->repository);

		$this->addHandler($this->outboxRecorder, $retryingRepository, $retryingRepository)(
			new AddStockCommand($productId, 5)
		);

		$row = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertCount(2, $retryingRepository->readStockItems);
		self::assertCount(2, $retryingRepository->savedStockItems);
		self::assertNotSame($retryingRepository->readStockItems[0], $retryingRepository->readStockItems[1]);
		self::assertSame([10, 10], $retryingRepository->readOnHand);
		self::assertSame([15, 15], $retryingRepository->savedOnHand);
		self::assertNotNull($row);
		self::assertSame(15, (int) $row->on_hand);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_added', $outboxRows[0]->event_name);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	private function registerHandler(
		OutboxRecorder $recorder,
		string $stockItemId
	): RegisterInitialStockCommandHandler {
		return new RegisterInitialStockCommandHandler(
			$this->repository,
			$this->transaction($recorder),
			new FixedUuidGenerator($stockItemId)
		);
	}

	private function addHandler(
		OutboxRecorder $recorder,
		StockItemExclusiveReader $reader,
		StockItemRepository $repository
	): AddStockCommandHandler {
		return new AddStockCommandHandler($reader, $repository, $this->transaction($recorder));
	}

	private function transaction(OutboxRecorder $recorder): StockItemTransaction
	{
		return new StockItemTransaction(
			new MySqlTransactionalSession($this->connection),
			new StockItemIntegrationEventRecorder($recorder, new StockItemIntegrationEventMapper())
		);
	}

	private function persistStockItem(string $stockItemId, string $productId, int $onHand): void
	{
		$this->trackAggregate($stockItemId);

		$stockItem = StockItem::register(
			new StockItemId($stockItemId),
			new ProductId($productId),
			new Quantity($onHand)
		);

		$this->repository->save($stockItem);
	}

	private function persistStockItemWithReservation(
		string $stockItemId,
		string $productId,
		string $reservationId
	): void {
		$this->trackAggregate($stockItemId);
		$this->trackReservation($reservationId);

		$stockItem = StockItem::register(new StockItemId($stockItemId), new ProductId($productId), new Quantity(10));
		$stockItem->pullDomainEvents();
		$stockItem->reserve(new ReservationId($reservationId), new OrderId(UuidMother::create()), new Quantity(3));

		$this->repository->save($stockItem);
	}

	private function trackAggregate(string $stockItemId): void
	{
		$this->aggregateIds[] = $stockItemId;
		$this->trackStockItem($stockItemId);
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

	/**
	 * @return array<string, mixed>
	 */
	private function outboxPayload(string $aggregateId): array
	{
		$rows = $this->outboxRows($aggregateId);

		self::assertCount(1, $rows);

		return json_decode((string) $rows[0]->payload, true, 512, JSON_THROW_ON_ERROR);
	}

	private function privateConnection(object $adapter): Connection
	{
		$property = new ReflectionProperty($adapter, 'connection');

		/** @var Connection $connection */
		$connection = $property->getValue($adapter);

		return $connection;
	}
}
