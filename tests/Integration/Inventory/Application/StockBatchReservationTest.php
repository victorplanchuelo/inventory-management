<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
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
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Domain\UuidGenerator;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\Inventory\Application\Support\CountingMySqlTransactionalSession;
use Tests\Integration\Inventory\Application\Support\FailingStockItemRepository;
use Tests\Integration\Inventory\InventoryIntegrationTestCase;
use Tests\Shared\Domain\SequenceUuidGenerator;
use Tests\Shared\Domain\UuidMother;
use Throwable;

final class StockBatchReservationTest extends InventoryIntegrationTestCase
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
	public function it_should_reserve_multiple_products_atomically_and_persist_every_counter_and_reservation(): void
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

		$this->handler(
			$this->repository,
			$this->repository,
			$this->outboxRecorder,
			new SequenceUuidGenerator([$firstReservation, $secondReservation])
		)(new ReserveStockBatchCommand($orderId, [
			new ReserveStockLine($secondProduct, 5),
			new ReserveStockLine($firstProduct, 3),
		]));

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->whereIn('stock_item_id', [$firstStockItem, $secondStockItem])
			->get()
			->all();
		$reservationsByStockItem = [];

		foreach ($reservationRows as $reservationRow) {
			$reservationsByStockItem[$reservationRow->stock_item_id] = $reservationRow;
		}

		self::assertNotNull($firstRow);
		self::assertSame(10, (int) $firstRow->on_hand);
		self::assertSame(3, (int) $firstRow->reserved);
		self::assertNotNull($secondRow);
		self::assertSame(20, (int) $secondRow->on_hand);
		self::assertSame(5, (int) $secondRow->reserved);
		self::assertCount(2, $reservationRows);
		self::assertSame($firstReservation, $reservationsByStockItem[$firstStockItem]->id);
		self::assertSame($firstStockItem, $reservationsByStockItem[$firstStockItem]->stock_item_id);
		self::assertSame($orderId, $reservationsByStockItem[$firstStockItem]->order_id);
		self::assertSame(3, (int) $reservationsByStockItem[$firstStockItem]->quantity);
		self::assertSame('active', $reservationsByStockItem[$firstStockItem]->status);
		self::assertSame($secondReservation, $reservationsByStockItem[$secondStockItem]->id);
		self::assertSame(5, (int) $reservationsByStockItem[$secondStockItem]->quantity);
		self::assertSame('active', $reservationsByStockItem[$secondStockItem]->status);

		$firstOutbox = $this->outboxRows($firstStockItem);
		$secondOutbox = $this->outboxRows($secondStockItem);
		self::assertCount(1, $firstOutbox);
		self::assertCount(1, $secondOutbox);
		self::assertSame('inventory.stock_reserved', $firstOutbox[0]->event_name);
		self::assertSame('inventory.stock_reserved', $secondOutbox[0]->event_name);
		self::assertSame($firstReservation, $this->outboxPayload($firstStockItem)['reservationId']);
		self::assertSame(3, $this->outboxPayload($firstStockItem)['reservedQuantity']);
		self::assertSame(3, $this->outboxPayload($firstStockItem)['reserved']);
		self::assertSame(7, $this->outboxPayload($firstStockItem)['available']);
		self::assertSame($secondReservation, $this->outboxPayload($secondStockItem)['reservationId']);
		self::assertSame(5, $this->outboxPayload($secondStockItem)['reservedQuantity']);
		self::assertSame(5, $this->outboxPayload($secondStockItem)['reserved']);
		self::assertSame(15, $this->outboxPayload($secondStockItem)['available']);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_every_product_when_one_reservation_fails(): void
	{
		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 2);

		try {
			$this->handler(
				$this->repository,
				$this->repository,
				$this->outboxRecorder,
				new SequenceUuidGenerator([UuidMother::create(), UuidMother::create()])
			)(new ReserveStockBatchCommand(UuidMother::create(), [
				new ReserveStockLine($secondProduct, 5),
				new ReserveStockLine($firstProduct, 4),
			]));
			self::fail('The insufficient stock was expected to fail the batch.');
		} catch (InsufficientStockException) {
			// Expected.
		}

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();

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
	public function it_should_write_one_outbox_record_per_reserved_product(): void
	{
		$products = [
			'00000000-0000-4000-8000-000000000001',
			'00000000-0000-4000-8000-000000000002',
			'00000000-0000-4000-8000-000000000003',
		];
		$stockItems = [UuidMother::create(), UuidMother::create(), UuidMother::create()];
		$reservations = [UuidMother::create(), UuidMother::create(), UuidMother::create()];
		$orderId = UuidMother::create();

		foreach ($products as $index => $product) {
			$this->persistStockItem($stockItems[$index], $product, 10);
		}

		$this->handler(
			$this->repository,
			$this->repository,
			$this->outboxRecorder,
			new SequenceUuidGenerator($reservations)
		)(new ReserveStockBatchCommand($orderId, [
			new ReserveStockLine($products[2], 2),
			new ReserveStockLine($products[0], 4),
			new ReserveStockLine($products[1], 6),
		]));

		foreach ($stockItems as $index => $stockItem) {
			$outboxRows = $this->outboxRows($stockItem);
			$payload = $this->outboxPayload($stockItem);

			self::assertCount(1, $outboxRows);
			self::assertSame('inventory.stock_reserved', $outboxRows[0]->event_name);
			self::assertSame($reservations[$index], $payload['reservationId']);
			self::assertSame([4, 6, 2][$index], $payload['reservedQuantity']);
			self::assertSame($orderId, $payload['orderId']);
		}

		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_leave_no_partial_state_when_a_save_fails(): void
	{
		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 10);

		try {
			$this->handler(
				$this->repository,
				new FailingStockItemRepository($this->repository),
				$this->outboxRecorder,
				new SequenceUuidGenerator([UuidMother::create(), UuidMother::create()])
			)(new ReserveStockBatchCommand(UuidMother::create(), [
				new ReserveStockLine($firstProduct, 3),
				new ReserveStockLine($secondProduct, 4),
			]));
			self::fail('The repository failure was expected to propagate.');
		} catch (RuntimeException $exception) {
			self::assertSame('Repository write failed.', $exception->getMessage());
		}

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();

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
	public function it_should_complete_two_concurrent_batches_with_reversed_input_order_without_a_deadlock(): void
	{
		$this->requirePcntl();

		$firstProduct = '00000000-0000-4000-8000-000000000001';
		$secondProduct = '00000000-0000-4000-8000-000000000002';
		$firstStockItem = UuidMother::create();
		$secondStockItem = UuidMother::create();
		$this->persistStockItem($firstStockItem, $firstProduct, 10);
		$this->persistStockItem($secondStockItem, $secondProduct, 10);
		$firstBatchReservations = [UuidMother::create(), UuidMother::create()];
		$secondBatchReservations = [UuidMother::create(), UuidMother::create()];
		$firstOrderId = UuidMother::create();
		$secondOrderId = UuidMother::create();

		$firstWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveBatchInChild(
				$connection,
				$firstOrderId,
				[new ReserveStockLine($firstProduct, 2), new ReserveStockLine($secondProduct, 3), ],
				$firstBatchReservations
			)
		);
		$secondWorker = $this->forkWorker(
			fn (Connection $connection): mixed => $this->reserveBatchInChild(
				$connection,
				$secondOrderId,
				[new ReserveStockLine($secondProduct, 4), new ReserveStockLine($firstProduct, 1), ],
				$secondBatchReservations
			)
		);

		$this->awaitReady($firstWorker);
		$this->awaitReady($secondWorker);
		$this->startWorker($firstWorker);
		$this->startWorker($secondWorker);

		$results = [$this->awaitWorker($firstWorker), $this->awaitWorker($secondWorker)];

		foreach ($results as $result) {
			self::assertTrue($result['ok']);
			self::assertSame(0, $result['transaction_level']);
			self::assertSame(1, $result['result']);
		}

		$firstRow = $this->connection->table('inventory_stock_items')->where('id', $firstStockItem)->first();
		$secondRow = $this->connection->table('inventory_stock_items')->where('id', $secondStockItem)->first();
		$reservationRows = $this->connection->table('inventory_stock_reservations')
			->whereIn('stock_item_id', [$firstStockItem, $secondStockItem])
			->get()
			->all();
		$reservationIds = array_map(static fn (object $row): string => (string) $row->id, $reservationRows);
		sort($reservationIds);
		$expectedReservationIds = array_merge($firstBatchReservations, $secondBatchReservations);
		sort($expectedReservationIds);

		self::assertNotNull($firstRow);
		self::assertSame(3, (int) $firstRow->reserved);
		self::assertNotNull($secondRow);
		self::assertSame(7, (int) $secondRow->reserved);
		self::assertCount(4, $reservationRows);
		self::assertSame($expectedReservationIds, $reservationIds);
		self::assertCount(2, $this->outboxRows($firstStockItem));
		self::assertCount(2, $this->outboxRows($secondStockItem));
		self::assertSame(
			['inventory.stock_reserved', 'inventory.stock_reserved'],
			array_map(static fn (object $row): string => (string) $row->event_name, $this->outboxRows($firstStockItem))
		);
	}

	private function handler(
		StockItemExclusiveReader $reader,
		StockItemRepository $repository,
		OutboxRecorder $recorder,
		UuidGenerator $uuidGenerator
	): ReserveStockBatchCommandHandler {
		return new ReserveStockBatchCommandHandler(
			$reader,
			$repository,
			new StockItemTransaction(
				new MySqlTransactionalSession($this->connection),
				new StockItemIntegrationEventRecorder($recorder, new StockItemIntegrationEventMapper())
			),
			$uuidGenerator
		);
	}

	/**
	 * @param list<ReserveStockLine> $lines
	 * @param list<string> $reservationIds
	 */
	private function reserveBatchInChild(
		Connection $connection,
		string $orderId,
		array $lines,
		array $reservationIds
	): int {
		$repository = new MySqlStockItemRepository($connection, new StockItemPersistenceMapper());
		$session = new CountingMySqlTransactionalSession($connection);
		$handler = new ReserveStockBatchCommandHandler(
			$repository,
			$repository,
			new StockItemTransaction(
				$session,
				new StockItemIntegrationEventRecorder(
					new MySqlOutboxRecorder($connection, new OutboxProducerValidator(['inventory'])),
					new StockItemIntegrationEventMapper()
				)
			),
			new SequenceUuidGenerator($reservationIds)
		);

		$handler(new ReserveStockBatchCommand($orderId, $lines));

		return $session->invocations;
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
			$this->fail('The batch concurrency worker could not be forked.');
		}

		if ($pid === 0) {
			fclose($parentSocket);
			$connection = null;

			try {
				DB::purge();
				DB::reconnect();
				$connection = DB::connection();
				$this->writeSignal($childSocket, "ready\n");
				$start = fread($childSocket, 2);

				if ($start !== 'go') {
					throw new RuntimeException('The batch concurrency worker did not receive the start signal.');
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

	private function awaitReady(int $pid): void
	{
		$socket = $this->workerSocket($pid);
		stream_set_timeout($socket, 10);
		$signal = fgets($socket);
		$signal = is_string($signal) ? trim($signal) : '';

		if ($signal !== 'ready') {
			$this->fail(sprintf('The batch concurrency worker <%d> did not become ready.', $pid));
		}
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
			$this->fail(sprintf('The batch concurrency worker <%d> returned no payload.', $pid));
		}

		$decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

		if (!is_array($decoded)) {
			$this->fail(sprintf('The batch concurrency worker <%d> returned an invalid payload.', $pid));
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
			$this->fail('The batch concurrency socket pair could not be created.');
		}

		return $pair;
	}

	/**
	 * @param resource $socket
	 */
	private function writeSignal(mixed $socket, string $signal): void
	{
		if (fwrite($socket, $signal) === false) {
			throw new RuntimeException('The batch concurrency signal could not be written.');
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
			$this->fail(sprintf('The batch concurrency worker <%d> is not registered.', $pid));
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
			$this->markTestSkipped('pcntl is required for the stock batch reservation tests.');
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
