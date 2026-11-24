<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommand;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommand;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\Inventory\Application\Support\FixedUuidGenerator;
use Tests\Integration\Inventory\Application\Support\ThrowingOutboxRecorder;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;

final class StockReservationAndReleaseAtomicityTest extends InventoryIntegrationTestCase
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
	public function it_should_commit_a_reservation_and_its_outbox_record_together(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$orderId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		$this->reserveHandler(
			$this->outboxRecorder,
			$this->repository,
			$this->repository,
			$reservationId
		)(new ReserveStockCommand($productId, $orderId, 4));

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertNotNull($stockRow);
		self::assertSame(10, (int) $stockRow->on_hand);
		self::assertSame(4, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame($stockItemId, $reservationRow->stock_item_id);
		self::assertSame($orderId, $reservationRow->order_id);
		self::assertSame(4, (int) $reservationRow->quantity);
		self::assertSame('active', $reservationRow->status);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_reserved', $outboxRows[0]->event_name);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_the_reservation_when_the_outbox_write_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		try {
			$this->reserveHandler(
				new ThrowingOutboxRecorder(),
				$this->repository,
				$this->repository,
				$reservationId
			)(new ReserveStockCommand($productId, UuidMother::create(), 4));
			self::fail('The outbox failure was expected to propagate.');
		} catch (RuntimeException $exception) {
			self::assertSame('Outbox write failed.', $exception->getMessage());
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();

		self::assertNotNull($stockRow);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertSame(
			0,
			$this->connection->table('inventory_stock_reservations')->where('id', $reservationId)->count()
		);
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_commit_a_release_and_its_outbox_record_together(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 3);

		$this->releaseHandler($this->outboxRecorder, $this->repository, $this->repository)(
			new ReleaseStockCommand($reservationId)
		);

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertNotNull($stockRow);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('released', $reservationRow->status);
		self::assertSame(3, (int) $reservationRow->quantity);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_released', $outboxRows[0]->event_name);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_the_release_when_the_outbox_write_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 3);

		try {
			$this->releaseHandler(
				new ThrowingOutboxRecorder(),
				$this->repository,
				$this->repository
			)(new ReleaseStockCommand($reservationId));
			self::fail('The outbox failure was expected to propagate.');
		} catch (RuntimeException $exception) {
			self::assertSame('Outbox write failed.', $exception->getMessage());
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();

		self::assertNotNull($stockRow);
		self::assertSame(3, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('active', $reservationRow->status);
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_persist_the_stock_reserved_payload_with_the_committed_state(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$orderId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		$this->reserveHandler(
			$this->outboxRecorder,
			$this->repository,
			$this->repository,
			$reservationId
		)(new ReserveStockCommand($productId, $orderId, 4));

		$payload = $this->outboxPayload($stockItemId);

		self::assertSame($stockItemId, $payload['stockItemId']);
		self::assertSame($productId, $payload['productId']);
		self::assertSame(10, $payload['onHand']);
		self::assertSame(4, $payload['reserved']);
		self::assertSame(6, $payload['available']);
		self::assertSame($orderId, $payload['orderId']);
		self::assertSame($reservationId, $payload['reservationId']);
		self::assertSame(4, $payload['reservedQuantity']);
	}

	#[Test]
	public function it_should_persist_the_stock_released_payload_with_the_committed_state(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$orderId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 3, 10, $orderId);

		$this->releaseHandler($this->outboxRecorder, $this->repository, $this->repository)(
			new ReleaseStockCommand($reservationId)
		);

		$payload = $this->outboxPayload($stockItemId);

		self::assertSame($stockItemId, $payload['stockItemId']);
		self::assertSame($productId, $payload['productId']);
		self::assertSame(10, $payload['onHand']);
		self::assertSame(0, $payload['reserved']);
		self::assertSame(10, $payload['available']);
		self::assertSame($orderId, $payload['orderId']);
		self::assertSame($reservationId, $payload['reservationId']);
		self::assertSame(3, $payload['releasedQuantity']);
	}

	#[Test]
	public function it_should_leave_no_success_outbox_record_when_the_reservation_fails(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);

		try {
			$this->reserveHandler(
				$this->outboxRecorder,
				$this->repository,
				$this->repository,
				$reservationId
			)(new ReserveStockCommand($productId, UuidMother::create(), 20));
			self::fail('The insufficient stock was expected to fail the reservation.');
		} catch (InsufficientStockException) {
			// Expected.
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();

		self::assertNotNull($stockRow);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertSame(
			0,
			$this->connection->table('inventory_stock_reservations')->where('id', $reservationId)->count()
		);
		self::assertSame([], $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_preserve_the_identity_and_status_of_a_released_reservation_after_reload(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$orderId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 3, 10, $orderId);

		$this->releaseHandler($this->outboxRecorder, $this->repository, $this->repository)(
			new ReleaseStockCommand($reservationId)
		);

		$reloaded = $this->repository->searchByReservationId(new ReservationId($reservationId));

		self::assertNotNull($reloaded);
		self::assertCount(1, $reloaded->reservations());
		self::assertSame($reservationId, $reloaded->reservations()[0]->id()->value());
		self::assertSame($orderId, $reloaded->reservations()[0]->orderId()->value());
		self::assertSame(3, $reloaded->reservations()[0]->quantity()->value());
		self::assertTrue($reloaded->reservations()[0]->isReleased());
		self::assertSame(0, $reloaded->reserved()->value());
		self::assertSame(10, $reloaded->onHand()->value());
	}

	private function reserveHandler(
		OutboxRecorder $recorder,
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		string $reservationId
	): ReserveStockCommandHandler {
		return new ReserveStockCommandHandler(
			$reader,
			$repository,
			$this->transaction($recorder),
			new FixedUuidGenerator($reservationId)
		);
	}

	private function releaseHandler(
		OutboxRecorder $recorder,
		StockItemExclusiveReader $reader,
		StockItemRepository $repository
	): ReleaseStockCommandHandler {
		return new ReleaseStockCommandHandler($reader, $repository, $this->transaction($recorder));
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
		$stockItem->pullDomainEvents();

		$this->repository->save($stockItem);
	}

	private function persistStockItemWithReservation(
		string $stockItemId,
		string $productId,
		string $reservationId,
		int $quantity,
		int $onHand = 10,
		?string $orderId = null
	): void {
		$this->trackAggregate($stockItemId);
		$this->trackReservation($reservationId);

		$stockItem = StockItem::register(
			new StockItemId($stockItemId),
			new ProductId($productId),
			new Quantity($onHand)
		);
		$stockItem->pullDomainEvents();
		$stockItem->reserve(
			new ReservationId($reservationId),
			new OrderId($orderId ?? UuidMother::create()),
			new Quantity($quantity)
		);

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
}
