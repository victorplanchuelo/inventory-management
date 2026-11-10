<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRepository;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlOutboxRepositoryTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';
	private const LEASE_SECONDS = 60;

	private Connection $connection;
	private Connection $secondConnection;
	private MySqlOutboxRepository $repository;
	private OutboxRecorder $recorder;

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->eventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();
		$this->repository = new MySqlOutboxRepository($this->connection, self::LEASE_SECONDS);
		$this->recorder = app(OutboxRecorder::class);

		$defaultConnection = config('database.default');
		$configuration = config('database.connections.' . $defaultConnection);

		config(['database.connections.outbox_secondary' => $configuration]);

		$this->secondConnection = DB::connection('outbox_secondary');
	}

	protected function tearDown(): void
	{
		if ($this->connection->transactionLevel() > 0) {
			$this->connection->rollBack();
		}

		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		DB::purge('outbox_secondary');

		parent::tearDown();
	}

	#[Test]
	public function it_should_claim_a_due_pending_record_with_a_fresh_token_and_increment_attempts(): void
	{
		$message = $this->append();
		$now = $this->now();

		$records = $this->repository->claim(10, $now, 'publisher-a');

		$this->assertCount(1, $records);

		$record = $records[0];

		$this->assertSame($message->eventId(), $record->eventId);
		$this->assertSame($message->eventName(), $record->eventName);
		$this->assertSame($message->payloadJson(), $record->payload);
		$this->assertSame(1, $record->attempts);
		$this->assertSame('publisher-a', $record->publisherId);
		$this->assertSame('claimed', $record->status->value);
		$this->assertNotNull($record->claimToken);
		$this->assertTrue(Uuid::isValid($record->claimToken));
		$this->assertSame('2026-11-10 12:00:00', $record->lockedAt?->format('Y-m-d H:i:s'));

		$row = $this->row($message->eventId());

		$this->assertSame('claimed', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertSame($record->claimToken, $row['claim_token']);
		$this->assertSame('publisher-a', $row['publisher_id']);
		$this->assertSame('2026-11-10 12:00:00', $row['locked_at']);
	}

	#[Test]
	public function it_should_only_claim_due_or_expired_records(): void
	{
		$duePending = $this->append('orders.due');
		$futurePending = $this->append('orders.future');
		$expiredClaim = $this->append('orders.expired');
		$liveClaim = $this->append('orders.live');

		$this->update($duePending->eventId(), [
			'next_attempt_at' => '2026-11-10 11:59:59',
			'attempts' => 0,
		]);
		$this->update($futurePending->eventId(), [
			'next_attempt_at' => '2026-11-10 12:01:00',
			'attempts' => 0,
		]);
		$this->update($expiredClaim->eventId(), [
			'status' => 'claimed',
			'locked_at' => '2026-11-10 11:58:59',
			'claim_token' => 'old-expired-token',
			'publisher_id' => 'old-publisher',
			'attempts' => 1,
		]);
		$this->update($liveClaim->eventId(), [
			'status' => 'claimed',
			'locked_at' => '2026-11-10 11:59:30',
			'claim_token' => 'old-live-token',
			'publisher_id' => 'old-publisher',
			'attempts' => 1,
		]);

		$records = $this->repository->claim(10, $this->now(), 'publisher-a');
		$claimedEventIds = array_map(static fn (OutboxRecord $record): string => $record->eventId, $records);
		$expectedEventIds = [$duePending->eventId(), $expiredClaim->eventId()];

		sort($claimedEventIds);
		sort($expectedEventIds);

		$this->assertSame($expectedEventIds, $claimedEventIds);

		$dueRow = $this->row($duePending->eventId());
		$expiredRow = $this->row($expiredClaim->eventId());
		$futureRow = $this->row($futurePending->eventId());
		$liveRow = $this->row($liveClaim->eventId());

		$this->assertSame(1, (int) $dueRow['attempts']);
		$this->assertNotSame('old-expired-token', $expiredRow['claim_token']);
		$this->assertSame(2, (int) $expiredRow['attempts']);

		$this->assertSame('pending', $futureRow['status']);
		$this->assertSame(0, (int) $futureRow['attempts']);
		$this->assertSame('claimed', $liveRow['status']);
		$this->assertSame('old-live-token', $liveRow['claim_token']);
	}

	#[Test]
	public function it_should_skip_a_row_locked_by_another_connection_and_recover_an_expired_claim(): void
	{
		$message = $this->append();
		$now = $this->now();
		$secondRepository = new MySqlOutboxRepository($this->secondConnection, self::LEASE_SECONDS);

		$this->connection->beginTransaction();

		try {
			$this->connection->table('outbox')
				->where('event_id', $message->eventId())
				->lockForUpdate()
				->first();

			$this->assertSame([], $secondRepository->claim(10, $now, 'publisher-b'));
		} finally {
			$this->connection->rollBack();
		}

		$firstClaim = $secondRepository->claim(10, $now, 'publisher-b');

		$this->assertCount(1, $firstClaim);
		$this->assertSame(1, $firstClaim[0]->attempts);

		$firstToken = $firstClaim[0]->claimToken;

		$this->assertNotNull($firstToken);
		$this->assertSame([], $this->repository->claim(10, $now, 'publisher-a'));

		$this->update($message->eventId(), [
			'locked_at' => '2026-11-10 11:58:59',
		]);

		$reclaimed = $this->repository->claim(10, $now, 'publisher-a');

		$this->assertCount(1, $reclaimed);
		$this->assertSame(2, $reclaimed[0]->attempts);
		$this->assertNotSame($firstToken, $reclaimed[0]->claimToken);
		$this->assertSame('publisher-a', $reclaimed[0]->publisherId);
	}

	#[Test]
	public function it_should_mark_published_only_with_the_current_claim_token(): void
	{
		$message = $this->append();
		$records = $this->repository->claim(10, $this->now(), 'publisher-a');
		$record = $records[0];

		$this->assertFalse($this->repository->markPublished($record->id, 'wrong-token', $this->now()));

		$stillClaimed = $this->row($message->eventId());

		$this->assertSame('claimed', $stillClaimed['status']);
		$this->assertNull($stillClaimed['published_at']);

		$this->assertTrue($this->repository->markPublished($record->id, (string) $record->claimToken, $this->now()));

		$published = $this->row($message->eventId());

		$this->assertSame('published', $published['status']);
		$this->assertSame('2026-11-10 12:00:00', $published['published_at']);
		$this->assertSame(1, (int) $published['attempts']);
		$this->assertNull($published['claim_token']);
		$this->assertNull($published['locked_at']);
		$this->assertNull($published['publisher_id']);
		$this->assertNull($published['last_error']);
	}

	#[Test]
	public function it_should_fence_a_stale_publisher_after_lease_recovery(): void
	{
		$message = $this->append();
		$now = $this->now();
		$firstClaim = $this->repository->claim(10, $now, 'publisher-a')[0];
		$firstToken = (string) $firstClaim->claimToken;

		$this->update($message->eventId(), [
			'locked_at' => '2026-11-10 11:58:59',
		]);

		$secondClaim = $this->repository->claim(10, $now, 'publisher-b')[0];
		$secondToken = (string) $secondClaim->claimToken;

		$this->assertNotSame($firstToken, $secondToken);
		$this->assertFalse($this->repository->markPublished($firstClaim->id, $firstToken, $now));

		$this->assertTrue($this->repository->markPublished($secondClaim->id, $secondToken, $now));

		$row = $this->row($message->eventId());

		$this->assertSame('published', $row['status']);
		$this->assertSame('2026-11-10 12:00:00', $row['published_at']);
		$this->assertNull($row['claim_token']);
	}

	#[Test]
	public function it_should_preserve_event_identity_and_payload_across_reclaims(): void
	{
		$payload = '{ "sku" : "A-1", "unicode" : "Málaga 🐢", "quantity" : 2 }';
		$message = $this->append(payload: $payload);
		$now = $this->now();

		$first = $this->repository->claim(10, $now, 'publisher-a')[0];

		$this->update($message->eventId(), [
			'locked_at' => '2026-11-10 11:58:59',
		]);

		$second = $this->repository->claim(10, $now, 'publisher-b')[0];

		$this->assertSame($first->eventId, $second->eventId);
		$this->assertSame($message->eventId(), $second->eventId);
		$this->assertSame($payload, $second->payload);
		$this->assertSame('2026-11-10 10:34:56', $second->occurredAt->format('Y-m-d H:i:s'));
		$this->assertSame(2, $second->attempts);
		$this->assertNotSame($first->claimToken, $second->claimToken);
	}

	#[Test]
	public function it_should_reject_an_invalid_claim_limit_and_lease(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->repository->claim(0, $this->now(), 'publisher-a');
	}

	#[Test]
	public function it_should_reject_a_lease_shorter_than_one_second(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new MySqlOutboxRepository($this->connection, 0);
	}

	private function append(
		string $eventName = 'orders.order_confirmed',
		string $payload = '{"orderId":"abc","total":19.90}',
	): OutboxMessage {
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		$message = new OutboxMessage(
			$eventId,
			$eventName,
			'1',
			'orders',
			'order',
			self::AGGREGATE_ID,
			$payload,
			new DateTimeImmutable('2026-11-10 10:34:56', new DateTimeZone('UTC')),
			7,
			'correlation-1',
			'causation-1',
		);

		$this->recorder->append($message);

		return $message;
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC'));
	}

	/** @return array<string, mixed> */
	private function row(string $eventId): array
	{
		$row = $this->connection->table('outbox')->where('event_id', $eventId)->first();

		$this->assertNotNull($row);

		/** @var array<string, mixed> $values */
		$values = (array) $row;

		return $values;
	}

	/** @param array<string, mixed> $values */
	private function update(string $eventId, array $values): void
	{
		$this->connection->table('outbox')->where('event_id', $eventId)->update($values);
	}
}
