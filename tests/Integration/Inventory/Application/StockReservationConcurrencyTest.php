<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Manager\Inventory\Application\AddStock\AddStockCommand;
use Manager\Inventory\Application\AddStock\AddStockCommandHandler;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommand;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommand;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\Exception\InsufficientStockException;
use Manager\Inventory\Domain\Exception\ReservationAlreadyReleasedException;
use Manager\Inventory\Domain\StockItem;
use Manager\Inventory\Domain\ValueObject\OrderId;
use Manager\Inventory\Domain\ValueObject\ProductId;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\Inventory\Application\Support\FixedUuidGenerator;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;
use Throwable;

final class StockReservationConcurrencyTest extends InventoryIntegrationTestCase
{
	private MySqlStockItemRepository $repository;
	private OutboxRecorder $outboxRecorder;

	/** @var list<string> */
	private array $aggregateIds = [];

	/** @var array<int, resource> */
	private array $workers = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->aggregateIds = [];
		$this->workers = [];
		$this->repository = app(MySqlStockItemRepository::class);
		$this->outboxRecorder = app(OutboxRecorder::class);
	}

	protected function tearDown(): void
	{
		$this->terminateWorkers();

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
	public function it_should_let_only_one_of_two_competing_reservations_take_the_remaining_stock(): void
	{
		$this->requirePcntl();

		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$firstReservationId = UuidMother::create();
		$secondReservationId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);
		$this->trackReservation($firstReservationId);
		$this->trackReservation($secondReservationId);

		$firstWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveInChild(
				$connection,
				$productId,
				UuidMother::create(),
				8,
				$firstReservationId
			)
		);
		$secondWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveInChild(
				$connection,
				$productId,
				UuidMother::create(),
				8,
				$secondReservationId
			)
		);

		$this->awaitReady($firstWorker);
		$this->awaitReady($secondWorker);
		$this->startWorker($firstWorker);
		$this->startWorker($secondWorker);

		$results = [$this->awaitWorker($firstWorker), $this->awaitWorker($secondWorker)];
		$successes = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === true));
		$failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));

		self::assertCount(1, $successes);
		self::assertCount(1, $failures);
		self::assertSame(InsufficientStockException::class, $failures[0]['error']);
		self::assertSame(0, $successes[0]['transaction_level']);
		self::assertSame(0, $failures[0]['transaction_level']);

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->where('stock_item_id', $stockItemId)
			->get();
		$outboxRows = $this->outboxRows($stockItemId);

		self::assertNotNull($stockRow);
		self::assertSame(10, (int) $stockRow->on_hand);
		self::assertSame(8, (int) $stockRow->reserved);
		self::assertLessThanOrEqual((int) $stockRow->on_hand, (int) $stockRow->reserved);
		self::assertCount(1, $reservationRows);
		self::assertSame('active', $reservationRows[0]->status);
		self::assertSame(8, (int) $reservationRows[0]->quantity);
		self::assertCount(1, $outboxRows);
		self::assertSame('inventory.stock_reserved', $outboxRows[0]->event_name);
	}

	#[Test]
	public function it_should_preserve_consistency_when_stock_is_added_concurrently_with_a_reservation(): void
	{
		$this->requirePcntl();

		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);
		$this->trackReservation($reservationId);

		$reserveWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveInChild(
				$connection,
				$productId,
				UuidMother::create(),
				8,
				$reservationId
			)
		);
		$addWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->addInChild($connection, $productId, 5)
		);

		$this->awaitReady($reserveWorker);
		$this->awaitReady($addWorker);
		$this->startWorker($reserveWorker);
		$this->startWorker($addWorker);

		$results = [$this->awaitWorker($reserveWorker), $this->awaitWorker($addWorker)];

		foreach ($results as $result) {
			self::assertTrue($result['ok']);
			self::assertSame(0, $result['transaction_level']);
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->where('stock_item_id', $stockItemId)
			->get();
		$outboxEventNames = array_map(
			static fn (object $row): string => (string) $row->event_name,
			$this->outboxRows($stockItemId)
		);
		sort($outboxEventNames);

		self::assertNotNull($stockRow);
		self::assertSame(15, (int) $stockRow->on_hand);
		self::assertSame(8, (int) $stockRow->reserved);
		self::assertCount(1, $reservationRows);
		self::assertSame('active', $reservationRows[0]->status);
		self::assertSame(8, (int) $reservationRows[0]->quantity);
		self::assertSame(['inventory.stock_added', 'inventory.stock_reserved'], $outboxEventNames);
	}

	#[Test]
	public function it_should_make_a_second_reservation_wait_and_evaluate_the_latest_committed_state(): void
	{
		$this->requirePcntl();

		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$parentReservationId = UuidMother::create();
		$childReservationId = UuidMother::create();
		$this->persistStockItem($stockItemId, $productId, 10);
		$this->trackReservation($parentReservationId);
		$this->trackReservation($childReservationId);

		$childWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveInChild(
				$connection,
				$productId,
				UuidMother::create(),
				8,
				$childReservationId
			)
		);
		$childConnectionId = $this->awaitReady($childWorker);
		$this->connection->beginTransaction();

		try {
			$stockItem = $this->repository->readByProductIdForUpdate(new ProductId($productId));
			self::assertNotNull($stockItem);
			$stockItem->reserve(new ReservationId($parentReservationId), new OrderId(UuidMother::create()), new Quantity(5));
			$this->repository->save($stockItem);

			$this->startWorker($childWorker);
			$this->awaitLockWait($childWorker, $childConnectionId);
			$this->connection->commit();
		} catch (Throwable $error) {
			if ($this->connection->transactionLevel() > 0) {
				$this->connection->rollBack();
			}

			throw $error;
		}

		$result = $this->awaitWorker($childWorker);
		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->where('stock_item_id', $stockItemId)
			->get();

		self::assertFalse($result['ok']);
		self::assertSame(InsufficientStockException::class, $result['error']);
		self::assertSame(0, $result['transaction_level']);
		self::assertNotNull($stockRow);
		self::assertSame(5, (int) $stockRow->reserved);
		self::assertCount(1, $reservationRows);
		self::assertSame($parentReservationId, $reservationRows[0]->id);
		self::assertSame(5, (int) $reservationRows[0]->quantity);
		self::assertSame([], $this->outboxRows($stockItemId));
	}

	#[Test]
	public function it_should_hold_the_owning_stock_item_lock_while_a_concurrent_release_waits(): void
	{
		$this->requirePcntl();

		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 4);

		$childWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->releaseInChild($connection, $reservationId)
		);
		$childConnectionId = $this->awaitReady($childWorker);
		$this->connection->beginTransaction();

		try {
			$stockItem = $this->repository->readByReservationIdForUpdate(new ReservationId($reservationId));
			self::assertNotNull($stockItem);

			$this->startWorker($childWorker);
			$this->awaitLockWait($childWorker, $childConnectionId);
			$this->connection->commit();
		} catch (Throwable $error) {
			if ($this->connection->transactionLevel() > 0) {
				$this->connection->rollBack();
			}

			throw $error;
		}

		$result = $this->awaitWorker($childWorker);
		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();

		self::assertTrue($result['ok']);
		self::assertSame(0, $result['transaction_level']);
		self::assertNotNull($stockRow);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('released', $reservationRow->status);
		self::assertCount(1, $this->outboxRows($stockItemId));
		self::assertSame('inventory.stock_released', $this->outboxRows($stockItemId)[0]->event_name);
	}

	#[Test]
	public function it_should_make_a_waiting_release_observe_the_latest_committed_state(): void
	{
		$this->requirePcntl();

		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 4);

		$childWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->releaseInChild($connection, $reservationId)
		);
		$childConnectionId = $this->awaitReady($childWorker);
		$this->connection->beginTransaction();

		try {
			$stockItem = $this->repository->readByReservationIdForUpdate(new ReservationId($reservationId));
			self::assertNotNull($stockItem);
			$stockItem->addStock(new Quantity(5));
			$this->repository->save($stockItem);

			$this->startWorker($childWorker);
			$this->awaitLockWait($childWorker, $childConnectionId);
			$this->connection->commit();
		} catch (Throwable $error) {
			if ($this->connection->transactionLevel() > 0) {
				$this->connection->rollBack();
			}

			throw $error;
		}

		$result = $this->awaitWorker($childWorker);
		$payload = $this->outboxPayload($stockItemId);
		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();

		self::assertTrue($result['ok']);
		self::assertSame(0, $result['transaction_level']);
		self::assertSame(15, $payload['onHand']);
		self::assertSame(0, $payload['reserved']);
		self::assertSame(15, $payload['available']);
		self::assertSame(4, $payload['releasedQuantity']);
		self::assertNotNull($stockRow);
		self::assertSame(15, (int) $stockRow->on_hand);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('released', $reservationRow->status);
	}

	#[Test]
	public function it_should_reject_a_second_release_of_the_same_reservation(): void
	{
		$stockItemId = UuidMother::create();
		$productId = UuidMother::create();
		$reservationId = UuidMother::create();
		$this->persistStockItemWithReservation($stockItemId, $productId, $reservationId, 4);

		$handler = new ReleaseStockCommandHandler($this->repository, $this->repository, $this->parentTransaction());
		$command = new ReleaseStockCommand($reservationId);
		$handler($command);

		try {
			$handler($command);
			self::fail('The second release of the same reservation was expected to fail.');
		} catch (ReservationAlreadyReleasedException) {
			// Expected.
		}

		$stockRow = $this->connection->table('inventory_stock_items')->where('id', $stockItemId)->first();
		$reservationRow = $this->connection->table('inventory_stock_reservations')
			->where('id', $reservationId)
			->first();

		self::assertNotNull($stockRow);
		self::assertSame(0, (int) $stockRow->reserved);
		self::assertNotNull($reservationRow);
		self::assertSame('released', $reservationRow->status);
		self::assertCount(1, $this->outboxRows($stockItemId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	private function reserveInChild(
		Connection $connection,
		string $productId,
		string $orderId,
		int $quantity,
		string $reservationId
	): void {
		$repository = new MySqlStockItemRepository($connection, new StockItemPersistenceMapper());
		$handler = new ReserveStockCommandHandler(
			$repository,
			$repository,
			$this->childTransaction($connection),
			new FixedUuidGenerator($reservationId)
		);

		$handler(new ReserveStockCommand($productId, $orderId, $quantity));
	}

	private function addInChild(Connection $connection, string $productId, int $quantity): void
	{
		$repository = new MySqlStockItemRepository($connection, new StockItemPersistenceMapper());
		$handler = new AddStockCommandHandler($repository, $repository, $this->childTransaction($connection));

		$handler(new AddStockCommand($productId, $quantity));
	}

	private function releaseInChild(Connection $connection, string $reservationId): void
	{
		$repository = new MySqlStockItemRepository($connection, new StockItemPersistenceMapper());
		$handler = new ReleaseStockCommandHandler($repository, $repository, $this->childTransaction($connection));

		$handler(new ReleaseStockCommand($reservationId));
	}

	private function childTransaction(Connection $connection): StockItemTransaction
	{
		return new StockItemTransaction(
			new MySqlTransactionalSession($connection),
			new StockItemIntegrationEventRecorder(
				new MySqlOutboxRecorder($connection, new OutboxProducerValidator(['inventory'])),
				new StockItemIntegrationEventMapper()
			)
		);
	}

	private function parentTransaction(): StockItemTransaction
	{
		return new StockItemTransaction(
			new MySqlTransactionalSession($this->connection),
			new StockItemIntegrationEventRecorder($this->outboxRecorder, new StockItemIntegrationEventMapper())
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
		int $onHand = 10
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
			new OrderId(UuidMother::create()),
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
	 * @param callable(Connection): mixed $operation
	 */
	private function forkWorker(callable $operation): int
	{
		[$parentSocket, $childSocket] = $this->socketPair();
		$pid = pcntl_fork();

		if ($pid === -1) {
			$this->fail('The concurrency worker could not be forked.');
		}

		if ($pid === 0) {
			fclose($parentSocket);
			$connection = null;

			try {
				DB::purge();
				DB::reconnect();
				$connection = DB::connection();
				$connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
				$this->writeSignal($childSocket, sprintf("ready:%d\n", $connectionId));
				$start = fread($childSocket, 2);

				if ($start !== 'go') {
					throw new RuntimeException('The concurrency worker did not receive the start signal.');
				}

				$result = $operation($connection);
				$payload = [
					'ok' => true,
					'result' => $result,
					'transaction_level' => $connection->transactionLevel(),
				];
			} catch (Throwable $error) {
				$payload = [
					'ok' => false,
					'error' => $error::class,
					'message' => $error->getMessage(),
					'transaction_level' => $connection instanceof Connection ? $connection->transactionLevel() : -1,
				];
			}

			fwrite($childSocket, json_encode($payload, JSON_THROW_ON_ERROR));
			fflush($childSocket);
			fclose($childSocket);

			exit(0);
		}

		fclose($childSocket);
		$this->workers[$pid] = $parentSocket;

		return $pid;
	}

	private function awaitReady(int $pid): int
	{
		$socket = $this->workerSocket($pid);
		stream_set_timeout($socket, 10);
		$signal = fgets($socket);
		$signal = is_string($signal) ? trim($signal) : '';

		if (!str_starts_with($signal, 'ready:')) {
			$this->fail(sprintf('The concurrency worker <%d> did not become ready.', $pid));
		}

		$connectionId = substr($signal, strlen('ready:'));

		if ($connectionId === '' || !ctype_digit($connectionId)) {
			$this->fail(sprintf('The concurrency worker <%d> reported an invalid connection identifier.', $pid));
		}

		return (int) $connectionId;
	}

	/**
	 * Waits until the worker's locking read is executing on its MySQL connection.
	 *
	 * `information_schema.processlist` exposes the statements of the current database
	 * user without requiring the PROCESS privilege. Because the parent already holds
	 * the row lock before starting the worker, observing the `FOR UPDATE` statement in
	 * the worker's connection proves that it is blocked on that lock.
	 */
	private function awaitLockWait(int $pid, int $connectionId): void
	{
		$deadline = microtime(true) + 10.0;

		do {
			$row = $this->connection->selectOne(
				'SELECT INFO FROM information_schema.processlist WHERE ID = ?',
				[$connectionId]
			);
			$statement = $row !== null && isset($row->INFO) && is_string($row->INFO)
				? strtolower($row->INFO)
				: '';

			if (str_contains($statement, 'inventory_stock_items') && str_contains($statement, 'for update')) {
				return;
			}

			usleep(10000);
		} while (microtime(true) < $deadline);

		$this->fail(sprintf('The concurrency worker <%d> did not enter a row-lock wait.', $pid));
	}

	private function startWorker(int $pid): void
	{
		$this->writeSignal($this->workerSocket($pid), 'go');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function awaitWorker(int $pid): array
	{
		$socket = $this->workerSocket($pid);
		stream_set_timeout($socket, 20);
		$payload = stream_get_contents($socket);
		fclose($socket);
		unset($this->workers[$pid]);

		$status = 0;
		pcntl_waitpid($pid, $status);

		if (!is_string($payload) || $payload === '') {
			$this->fail(sprintf('The concurrency worker <%d> returned no payload.', $pid));
		}

		$decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

		if (!is_array($decoded)) {
			$this->fail(sprintf('The concurrency worker <%d> returned an invalid payload.', $pid));
		}

		return $decoded;
	}

	/**
	 * @return array{resource, resource}
	 */
	private function socketPair(): array
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

		if ($pair === false) {
			$this->fail('The concurrency socket pair could not be created.');
		}

		return $pair;
	}

	/**
	 * @param resource $socket
	 */
	private function writeSignal(mixed $socket, string $signal): void
	{
		if (fwrite($socket, $signal) === false) {
			throw new RuntimeException('The concurrency signal could not be written.');
		}

		fflush($socket);
	}

	/**
	 * @return resource
	 */
	private function workerSocket(int $pid): mixed
	{
		$socket = $this->workers[$pid] ?? null;

		if (!is_resource($socket)) {
			$this->fail(sprintf('The concurrency worker <%d> is not registered.', $pid));
		}

		return $socket;
	}

	private function terminateWorkers(): void
	{
		foreach ($this->workers as $pid => $socket) {
			if (is_resource($socket)) {
				fclose($socket);
			}

			$status = 0;
			$waited = pcntl_waitpid($pid, $status, WNOHANG);

			if ($waited === 0) {
				if (function_exists('posix_kill')) {
					posix_kill($pid, SIGKILL);
				}

				pcntl_waitpid($pid, $status);
			}
		}

		$this->workers = [];
	}

	private function requirePcntl(): void
	{
		if (!function_exists('pcntl_fork')) {
			$this->markTestSkipped('pcntl is required for the stock reservation concurrency tests.');
		}
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
