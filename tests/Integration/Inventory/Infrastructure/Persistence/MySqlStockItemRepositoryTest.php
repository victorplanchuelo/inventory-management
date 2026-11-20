<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Domain\Exception\StockItemAlreadyExistsException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;
use Throwable;

final class MySqlStockItemRepositoryTest extends InventoryIntegrationTestCase
{
	private StockItemPersistenceMapper $mapper;
	private MySqlStockItemRepository $repository;

	protected function setUp(): void
	{
		parent::setUp();

		$this->mapper = app(StockItemPersistenceMapper::class);
		$this->repository = app(StockItemRepository::class);
	}

	#[Test]
	public function it_should_bind_the_repository_and_the_exclusive_reader_to_the_same_adapter(): void
	{
		$repository = app(StockItemRepository::class);
		$exclusiveReader = app(StockItemExclusiveReader::class);

		self::assertInstanceOf(MySqlStockItemRepository::class, $repository);
		self::assertSame($repository, $exclusiveReader);
	}

	#[Test]
	public function it_should_save_and_retrieve_a_stock_item_by_its_identifier(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(25));

		$this->save($stockItem);

		$reloaded = $this->repository->search($stockItem->id());

		self::assertNotNull($reloaded);
		self::assertSame($stockItem->id()->value(), $reloaded->id()->value());
		self::assertSame($stockItem->productId()->value(), $reloaded->productId()->value());
		self::assertSame(25, $reloaded->onHand()->value());
		self::assertSame(0, $reloaded->reserved()->value());
		self::assertSame(25, $reloaded->available()->value());
	}

	#[Test]
	public function it_should_retrieve_a_stock_item_by_its_product_identifier(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(15));

		$this->save($stockItem);

		$reloaded = $this->repository->searchByProductId($stockItem->productId());

		self::assertNotNull($reloaded);
		self::assertSame($stockItem->id()->value(), $reloaded->id()->value());
	}

	#[Test]
	public function it_should_find_the_owning_stock_item_by_a_reservation_identifier(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(30));
		$reservationId = $this->reservationId();
		$stockItem->pullDomainEvents();
		$stockItem->reserve($reservationId, $this->orderId(), new Quantity(10));

		$this->save($stockItem);

		$reloaded = $this->repository->searchByReservationId($reservationId);

		self::assertNotNull($reloaded);
		self::assertSame($stockItem->id()->value(), $reloaded->id()->value());
		self::assertSame(10, $reloaded->reserved()->value());
	}

	#[Test]
	public function it_should_return_null_for_unknown_identifiers(): void
	{
		self::assertNull($this->repository->search($this->stockItemId()));
		self::assertNull($this->repository->searchByProductId($this->productId()));
		self::assertNull($this->repository->searchByReservationId($this->reservationId()));
	}

	#[Test]
	public function it_should_enforce_one_stock_item_per_product(): void
	{
		$productId = $this->productId();
		$firstStockItem = StockItem::register($this->stockItemId(), $productId, new Quantity(10));
		$this->save($firstStockItem);

		$secondStockItem = StockItem::register($this->stockItemId(), $productId, new Quantity(5));

		$this->expectException(StockItemAlreadyExistsException::class);

		$this->repository->save($secondStockItem);
	}

	#[Test]
	public function it_should_persist_and_reconstruct_active_reservations(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(30));
		$reservationId = $this->reservationId();
		$orderId = $this->orderId();
		$stockItem->pullDomainEvents();
		$stockItem->reserve($reservationId, $orderId, new Quantity(10));

		$this->save($stockItem);

		$reloaded = $this->repository->search($stockItem->id());

		self::assertNotNull($reloaded);
		self::assertCount(1, $reloaded->reservations());
		self::assertSame($reservationId->value(), $reloaded->reservations()[0]->id()->value());
		self::assertSame($orderId->value(), $reloaded->reservations()[0]->orderId()->value());
		self::assertSame(10, $reloaded->reservations()[0]->quantity()->value());
		self::assertTrue($reloaded->reservations()[0]->isActive());
	}

	#[Test]
	public function it_should_persist_and_reconstruct_released_reservations(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(30));
		$reservationId = $this->reservationId();
		$stockItem->pullDomainEvents();
		$stockItem->reserve($reservationId, $this->orderId(), new Quantity(10));
		$stockItem->releaseReservation($reservationId);

		$this->save($stockItem);

		$reloaded = $this->repository->search($stockItem->id());

		self::assertNotNull($reloaded);
		self::assertCount(1, $reloaded->reservations());
		self::assertTrue($reloaded->reservations()[0]->isReleased());
		self::assertSame(0, $reloaded->reserved()->value());
		self::assertSame(30, $reloaded->available()->value());
	}

	#[Test]
	public function it_should_update_the_on_hand_and_reserved_counters(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(10));
		$this->save($stockItem);

		$stockItem->pullDomainEvents();
		$stockItem->addStock(new Quantity(5));
		$stockItem->reserve($this->reservationId(), $this->orderId(), new Quantity(4));
		$this->save($stockItem);

		$reloaded = $this->repository->search($stockItem->id());
		$row = $this->connection->table('inventory_stock_items')
			->where('id', $stockItem->id()->value())
			->first();

		self::assertNotNull($reloaded);
		self::assertNotNull($row);
		self::assertSame(15, $reloaded->onHand()->value());
		self::assertSame(4, $reloaded->reserved()->value());
		self::assertSame(11, $reloaded->available()->value());
		self::assertSame(15, (int) $row->on_hand);
		self::assertSame(4, (int) $row->reserved);
	}

	#[Test]
	public function it_should_update_a_reservation_status_without_changing_its_created_at(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(30));
		$reservationId = $this->reservationId();
		$stockItem->pullDomainEvents();
		$stockItem->reserve($reservationId, $this->orderId(), new Quantity(10));
		$this->save($stockItem);

		$this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId->value())
			->update([
				'created_at' => '2020-01-02 03:04:05',
				'updated_at' => '2020-01-02 03:04:05',
			]);

		$stockItem->releaseReservation($reservationId);
		$this->save($stockItem);

		$rows = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId->value())
			->get();

		self::assertCount(1, $rows);
		self::assertSame('released', $rows[0]->status);
		self::assertSame('2020-01-02 03:04:05', $rows[0]->created_at);
		self::assertNotSame('2020-01-02 03:04:05', $rows[0]->updated_at);
	}

	#[Test]
	public function it_should_preserve_the_complete_aggregate_state_after_reloading(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(50));
		$activeReservationId = $this->reservationId();
		$releasedReservationId = $this->reservationId();
		$stockItem->pullDomainEvents();
		$stockItem->addStock(new Quantity(5));
		$stockItem->reserve($activeReservationId, $this->orderId(), new Quantity(10));
		$stockItem->reserve($releasedReservationId, $this->orderId(), new Quantity(3));
		$stockItem->releaseReservation($releasedReservationId);

		$this->save($stockItem);

		$reloaded = $this->repository->search($stockItem->id());

		self::assertNotNull($reloaded);
		self::assertSame(55, $reloaded->onHand()->value());
		self::assertSame(10, $reloaded->reserved()->value());
		self::assertSame(45, $reloaded->available()->value());
		self::assertCount(2, $reloaded->reservations());

		$reservationsById = [];

		foreach ($reloaded->reservations() as $reservation) {
			$reservationsById[$reservation->id()->value()] = $reservation;
		}

		self::assertArrayHasKey($activeReservationId->value(), $reservationsById);
		self::assertArrayHasKey($releasedReservationId->value(), $reservationsById);
		self::assertTrue($reservationsById[$activeReservationId->value()]->isActive());
		self::assertTrue($reservationsById[$releasedReservationId->value()]->isReleased());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function exclusiveReaderMethods(): array
	{
		return [
			'by stock item identifier' => ['id'],
			'by product identifier' => ['product'],
			'by reservation identifier' => ['reservation'],
		];
	}

	#[Test]
	#[DataProvider('exclusiveReaderMethods')]
	public function it_should_hold_the_stock_item_lock_until_the_transaction_commits_or_rolls_back(string $method): void
	{
		$stockItem = $this->persistedStockItemWithReservation();
		$secondConnection = $this->secondConnection();
		$secondRepository = new MySqlStockItemRepository($secondConnection, $this->mapper);

		try {
			$this->assertSecondConnectionWaitsForTheLock(
				$stockItem,
				$secondRepository,
				$secondConnection,
				$method,
				commit: true
			);
			$this->assertSecondConnectionWaitsForTheLock(
				$stockItem,
				$secondRepository,
				$secondConnection,
				$method,
				commit: false
			);
		} finally {
			DB::purge('inventory_lock_probe');
		}
	}

	#[Test]
	#[DataProvider('exclusiveReaderMethods')]
	public function it_should_reject_an_exclusive_read_outside_a_transaction_before_issuing_a_query(string $method): void
	{
		$stockItem = $this->persistedStockItemWithReservation();
		$this->connection->enableQueryLog();
		$this->connection->flushQueryLog();
		$queries = [];

		try {
			$this->exclusiveRead($this->repository, $stockItem, $method);
			self::fail('A LogicException was expected for an exclusive read outside a transaction.');
		} catch (LogicException $exception) {
			self::assertStringContainsString('active transaction', $exception->getMessage());
		} finally {
			$queries = $this->connection->getQueryLog();
			$this->connection->disableQueryLog();
			$this->connection->flushQueryLog();
		}

		self::assertSame([], $queries);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_store_utc_wall_clock_timestamps_when_the_mysql_session_timezone_is_not_utc(): void
	{
		$this->withSessionTimeZone('+05:30', function (): void {
			$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(10));
			$this->save($stockItem);

			$row = $this->connection->table('inventory_stock_items')
				->where('id', $stockItem->id()->value())
				->first();

			self::assertNotNull($row);

			$storedCreatedAt = new DateTimeImmutable((string) $row->created_at, new DateTimeZone('UTC'));
			$storedUpdatedAt = new DateTimeImmutable((string) $row->updated_at, new DateTimeZone('UTC'));
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

			self::assertLessThanOrEqual(2, abs($now->getTimestamp() - $storedCreatedAt->getTimestamp()));
			self::assertLessThanOrEqual(2, abs($now->getTimestamp() - $storedUpdatedAt->getTimestamp()));
		});
	}

	#[Test]
	public function it_should_convert_timestamps_supplied_in_a_non_utc_timezone_to_utc_before_persisting(): void
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(10));
		$localTimestamp = new DateTimeImmutable('2026-11-19 10:15:00', new DateTimeZone('Asia/Tokyo'));

		$row = $this->mapper->stockItemRow($stockItem, $localTimestamp, $localTimestamp);

		self::assertSame('2026-11-19 01:15:00', $row['created_at']);
		self::assertSame('2026-11-19 01:15:00', $row['updated_at']);

		$this->withSessionTimeZone('+09:00', function () use ($stockItem, $row): void {
			$this->trackStockItem($stockItem->id()->value());
			$this->connection->table('inventory_stock_items')->insert($row);

			$persisted = $this->connection->table('inventory_stock_items')
				->where('id', $stockItem->id()->value())
				->first();

			self::assertNotNull($persisted);
			self::assertSame('2026-11-19 01:15:00', $persisted->created_at);
			self::assertSame('2026-11-19 01:15:00', $persisted->updated_at);
		});
	}

	private function withSessionTimeZone(string $timeZone, callable $operation): void
	{
		$originalTimeZone = $this->sessionTimeZone();
		$this->setSessionTimeZone($timeZone);

		try {
			$operation();
		} finally {
			$this->setSessionTimeZone($originalTimeZone);
		}
	}

	private function sessionTimeZone(): string
	{
		$row = $this->connection->selectOne('SELECT @@session.time_zone AS time_zone');

		if (!is_object($row) || !isset($row->time_zone) || !is_string($row->time_zone)) {
			throw new LogicException('The MySQL session time zone could not be read.');
		}

		return $row->time_zone;
	}

	private function setSessionTimeZone(string $timeZone): void
	{
		$this->connection->statement(sprintf(
			'SET SESSION time_zone = %s',
			$this->connection->getPdo()->quote($timeZone)
		));
	}

	private function save(StockItem $stockItem): void
	{
		$this->trackStockItem($stockItem->id()->value());

		foreach ($stockItem->reservations() as $reservation) {
			$this->trackReservation($reservation->id()->value());
		}

		$this->repository->save($stockItem);
	}

	private function persistedStockItemWithReservation(): StockItem
	{
		$stockItem = StockItem::register($this->stockItemId(), $this->productId(), new Quantity(50));
		$stockItem->pullDomainEvents();
		$stockItem->reserve($this->reservationId(), $this->orderId(), new Quantity(10));
		$this->save($stockItem);

		return $stockItem;
	}

	private function assertSecondConnectionWaitsForTheLock(
		StockItem $stockItem,
		MySqlStockItemRepository $secondRepository,
		Connection $secondConnection,
		string $method,
		bool $commit
	): void {
		$this->connection->beginTransaction();

		try {
			$this->exclusiveRead($this->repository, $stockItem, $method);

			$secondConnection->beginTransaction();

			$startedAt = microtime(true);
			$blocked = false;

			try {
				$this->exclusiveRead($secondRepository, $stockItem, $method);
			} catch (QueryException $exception) {
				self::assertSame(
					1205,
					(int) ($exception->errorInfo[1] ?? 0),
					'The waiting connection failed for a reason other than a lock wait timeout.'
				);
				$blocked = true;
			} finally {
				$elapsed = microtime(true) - $startedAt;
				$secondConnection->rollBack();
			}

			self::assertTrue($blocked, 'The second connection did not wait for the stock item lock.');
			self::assertGreaterThanOrEqual(0.9, $elapsed);

			if ($commit) {
				$this->connection->commit();
			} else {
				$this->connection->rollBack();
			}
		} catch (Throwable $error) {
			if ($this->connection->transactionLevel() > 0) {
				$this->connection->rollBack();
			}

			throw $error;
		}

		$secondConnection->beginTransaction();

		try {
			self::assertNotNull($this->exclusiveRead($secondRepository, $stockItem, $method));
		} finally {
			$secondConnection->rollBack();
		}
	}

	private function exclusiveRead(
		MySqlStockItemRepository $repository,
		StockItem $stockItem,
		string $method
	): ?StockItem {
		return match ($method) {
			'id' => $repository->readByIdForUpdate($stockItem->id()),
			'product' => $repository->readByProductIdForUpdate($stockItem->productId()),
			'reservation' => $repository->readByReservationIdForUpdate($stockItem->reservations()[0]->id()),
			default => throw new LogicException(sprintf('Unknown exclusive-read method <%s>.', $method)),
		};
	}

	private function secondConnection(): Connection
	{
		$defaultConnection = config('database.default');
		$configuration = config('database.connections.' . $defaultConnection);

		config(['database.connections.inventory_lock_probe' => $configuration]);

		DB::purge('inventory_lock_probe');
		$connection = DB::connection('inventory_lock_probe');
		$connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

		return $connection;
	}

	private function stockItemId(): StockItemId
	{
		return new StockItemId(UuidMother::create());
	}

	private function productId(): ProductId
	{
		return new ProductId(UuidMother::create());
	}

	private function reservationId(): ReservationId
	{
		return new ReservationId(UuidMother::create());
	}

	private function orderId(): OrderId
	{
		return new OrderId(UuidMother::create());
	}
}
