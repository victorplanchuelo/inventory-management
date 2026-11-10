<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlTransactionalSessionTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';
	private const BUSINESS_ID = 'c71f5f0e-b6f1-4a8d-9d0e-1c1a3f6d7e21';

	private Connection $connection;
	private TransactionalSession $session;
	private OutboxRecorder $recorder;
	private string $businessTable;

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->eventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();
		$this->businessTable = 'outbox_probe_' . str_replace('-', '', SimpleUuid::random()->value());

		$this->connection->statement(
			'CREATE TABLE ' . $this->businessTable . ' (id VARCHAR(36) NOT NULL PRIMARY KEY, note VARCHAR(255) NOT NULL) ENGINE=InnoDB'
		);

		$this->session = new MySqlTransactionalSession($this->connection);
		$this->recorder = new MySqlOutboxRecorder(
			$this->connection,
			new OutboxProducerValidator(['inventory', 'orders', 'catalog'])
		);
	}

	protected function tearDown(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		$this->connection->statement('DROP TABLE IF EXISTS ' . $this->businessTable);

		parent::tearDown();
	}

	#[Test]
	public function it_should_inject_the_identical_connection_object_into_both_adapters(): void
	{
		$factoryConnection = app(OutboxConnectionFactory::class)->connection();
		$sessionConnection = $this->connectionOf(app(TransactionalSession::class));
		$recorderConnection = $this->connectionOf(app(OutboxRecorder::class));

		$this->assertSame($this->connection, $factoryConnection);
		$this->assertSame($factoryConnection, $sessionConnection);
		$this->assertSame($factoryConnection, $recorderConnection);
		$this->assertSame(DB::connection(), $factoryConnection);
	}

	#[Test]
	public function it_should_not_allow_an_independent_outbox_connection(): void
	{
		$this->assertArrayNotHasKey('connection', config('outbox'));

		$defaultConnection = config('database.default');

		config([
			'database.connections.outbox_decoy' => config('database.connections.' . $defaultConnection),
			'outbox.connection' => 'outbox_decoy',
		]);

		$factoryConnection = app(OutboxConnectionFactory::class)->connection();
		$sessionConnection = $this->connectionOf(app(TransactionalSession::class));
		$recorderConnection = $this->connectionOf(app(OutboxRecorder::class));

		$this->assertSame(DB::connection(), $factoryConnection);
		$this->assertSame($factoryConnection, $sessionConnection);
		$this->assertSame($factoryConnection, $recorderConnection);
	}

	#[Test]
	public function it_should_commit_the_business_row_and_the_outbox_row_together(): void
	{
		$message = $this->message();

		$this->session->execute(function () use ($message): void {
			$this->insertBusinessRow('committed');

			$this->recorder->append($message);
		});

		$this->assertSame(1, $this->businessRows()->count());

		$persistedEvent = $this->persistedEvent($message);

		$this->assertNotNull($persistedEvent);
		$this->assertSame('inventory', $persistedEvent->producer);
		$this->assertSame('pending', $persistedEvent->status);
		$this->assertSame(0, (int) $persistedEvent->attempts);
	}

	#[Test]
	public function it_should_roll_back_the_business_row_and_the_outbox_row_when_the_operation_throws(): void
	{
		$message = $this->message();
		$caught = null;

		try {
			$this->session->execute(function () use ($message): void {
				$this->insertBusinessRow('rolled back');

				$this->recorder->append($message);

				throw new RuntimeException('business operation failed');
			});
		} catch (RuntimeException $exception) {
			$caught = $exception;
		}

		$this->assertNotNull($caught);
		$this->assertSame('business operation failed', $caught->getMessage());
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->connection->table('outbox')->where('event_id', $message->eventId())->count());
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_roll_back_the_business_row_when_the_outbox_write_fails(): void
	{
		$message = $this->message();

		$this->recorder->append($message);

		$caught = null;

		try {
			$this->session->execute(function () use ($message): void {
				$this->insertBusinessRow('must not persist');

				$this->recorder->append($message);
			});
		} catch (QueryException) {
			$caught = true;
		}

		$this->assertTrue($caught);
		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(1, $this->connection->table('outbox')->where('event_id', $message->eventId())->count());
		$this->assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_leave_the_transaction_open_when_the_recorder_appends(): void
	{
		$message = $this->message();

		$this->assertSame(0, $this->connection->transactionLevel());

		$this->session->execute(function () use ($message): void {
			$this->assertSame(1, $this->connection->transactionLevel());

			$this->recorder->append($message);

			$this->assertSame(1, $this->connection->transactionLevel());
		});

		$this->assertSame(0, $this->connection->transactionLevel());
		$this->assertSame(1, $this->connection->table('outbox')->where('event_id', $message->eventId())->count());
	}

	private function connectionOf(object $adapter): Connection
	{
		$property = new ReflectionProperty($adapter, 'connection');

		/** @var Connection $connection */
		$connection = $property->getValue($adapter);

		return $connection;
	}

	private function insertBusinessRow(string $note): void
	{
		$this->connection->table($this->businessTable)->insert([
			'id' => self::BUSINESS_ID,
			'note' => $note,
		]);
	}

	private function businessRows(): Builder
	{
		return $this->connection->table($this->businessTable)->where('id', self::BUSINESS_ID);
	}

	private function persistedEvent(OutboxMessage $message): ?object
	{
		return $this->connection->table('outbox')
			->where('event_id', $message->eventId())
			->first();
	}

	private function message(): OutboxMessage
	{
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		return new OutboxMessage(
			$eventId,
			'inventory.stock_added',
			'1',
			'inventory',
			'stock_item',
			self::AGGREGATE_ID,
			'{"sku":"A-1","quantity":2}',
			new DateTimeImmutable('2026-11-10 12:34:56', new DateTimeZone('UTC')),
			7,
			'correlation-1',
			'causation-1',
		);
	}
}
