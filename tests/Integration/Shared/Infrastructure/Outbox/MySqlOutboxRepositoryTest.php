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
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use Manager\Shared\Infrastructure\Outbox\OutboxRetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlOutboxRepositoryTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';
	private const LEASE_SECONDS = 60;
	private const SECRET_ERROR = 'Connection amqp://guest:s3cr3t-hunter2@rabbitmq:5672 failed; password=s3cr3t-hunter2 token=s3cr3t-hunter2';

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
		$this->repository = new MySqlOutboxRepository(
			$this->connection,
			self::LEASE_SECONDS,
			new OutboxErrorSanitizer(),
		);
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
		$secondRepository = new MySqlOutboxRepository(
			$this->secondConnection,
			self::LEASE_SECONDS,
			new OutboxErrorSanitizer(),
		);

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
	public function it_should_schedule_a_retry_with_the_current_claim_token(): void
	{
		$message = $this->append();
		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;
		$nextAttemptAt = $this->now()->modify('+5 seconds');
		$lastError = 'OutboxPublicationException: RabbitMQ confirmation timed out.';

		$this->assertFalse($this->repository->scheduleRetry($record->id, 'wrong-token', $nextAttemptAt, $lastError));

		$stillClaimed = $this->row($message->eventId());

		$this->assertSame('claimed', $stillClaimed['status']);
		$this->assertSame($claimToken, $stillClaimed['claim_token']);

		$this->assertTrue($this->repository->scheduleRetry($record->id, $claimToken, $nextAttemptAt, $lastError));

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame('2026-11-10 12:00:05', $row['next_attempt_at']);
		$this->assertSame($lastError, $row['last_error']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNull($row['published_at']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['publisher_id']);
		$this->assertNull($row['failed_at']);

		$this->assertSame([], $this->repository->claim(10, $this->now(), 'publisher-b'));

		$retried = $this->repository->claim(10, $this->now()->modify('+5 seconds'), 'publisher-b');

		$this->assertCount(1, $retried);
		$this->assertSame(2, $retried[0]->attempts);
		$this->assertSame($message->eventId(), $retried[0]->eventId);
	}

	#[Test]
	public function it_should_sanitize_secrets_before_persisting_a_retry_error(): void
	{
		$message = $this->append();
		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;

		$this->assertTrue(
			$this->repository->scheduleRetry(
				$record->id,
				$claimToken,
				$this->now()->modify('+5 seconds'),
				self::SECRET_ERROR,
			)
		);

		$lastError = (string) $this->row($message->eventId())['last_error'];

		$this->assertStringContainsString('[REDACTED]', $lastError);
		$this->assertStringNotContainsString('s3cr3t-hunter2', $lastError);
		$this->assertStringNotContainsString('guest', $lastError);
	}

	#[Test]
	public function it_should_sanitize_secrets_before_persisting_a_terminal_error(): void
	{
		$message = $this->append();
		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;

		$this->assertTrue($this->repository->markFailed($record->id, $claimToken, $this->now(), self::SECRET_ERROR));

		$lastError = (string) $this->row($message->eventId())['last_error'];

		$this->assertStringContainsString('[REDACTED]', $lastError);
		$this->assertStringNotContainsString('s3cr3t-hunter2', $lastError);
		$this->assertStringNotContainsString('guest', $lastError);
	}

	#[Test]
	public function it_should_fence_retry_scheduling_with_the_current_claim_token(): void
	{
		$message = $this->append();
		$firstClaim = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$firstToken = (string) $firstClaim->claimToken;

		$this->update($message->eventId(), [
			'locked_at' => '2026-11-10 11:58:59',
		]);

		$secondClaim = $this->repository->claim(10, $this->now(), 'publisher-b')[0];
		$secondToken = (string) $secondClaim->claimToken;

		$this->assertNotSame($firstToken, $secondToken);
		$this->assertFalse(
			$this->repository->scheduleRetry(
				$firstClaim->id,
				$firstToken,
				$this->now()->modify('+5 seconds'),
				'stale publisher'
			)
		);

		$stillClaimed = $this->row($message->eventId());

		$this->assertSame('claimed', $stillClaimed['status']);
		$this->assertSame($secondToken, $stillClaimed['claim_token']);
		$this->assertSame(2, (int) $stillClaimed['attempts']);

		$this->assertTrue(
			$this->repository->scheduleRetry(
				$secondClaim->id,
				$secondToken,
				$this->now()->modify('+10 seconds'),
				'current publisher'
			)
		);

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(2, (int) $row['attempts']);
		$this->assertSame('2026-11-10 12:00:10', $row['next_attempt_at']);
		$this->assertNull($row['claim_token']);
	}

	#[Test]
	public function it_should_refuse_to_schedule_a_retry_at_the_attempt_limit(): void
	{
		$message = $this->append();

		$this->update($message->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS - 1,
		]);

		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;

		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, $record->attempts);
		$this->assertFalse(
			$this->repository->scheduleRetry(
				$record->id,
				$claimToken,
				$this->now()->modify('+5 seconds'),
				'retry at the attempt limit'
			)
		);

		$row = $this->row($message->eventId());

		$this->assertSame('claimed', $row['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $row['attempts']);
		$this->assertSame($claimToken, $row['claim_token']);
		$this->assertNull($row['next_attempt_at']);

		$this->assertTrue($this->repository->markFailed($record->id, $claimToken, $this->now(), 'terminal failure'));

		$failed = $this->row($message->eventId());

		$this->assertSame('failed', $failed['status']);
		$this->assertSame('2026-11-10 12:00:00', $failed['failed_at']);
	}

	#[Test]
	public function it_should_mark_a_claimed_record_as_failed_with_the_current_claim_token(): void
	{
		$message = $this->append();
		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;
		$lastError = 'OutboxSerializationException: The envelope could not be serialized.';

		$this->assertFalse($this->repository->markFailed($record->id, 'wrong-token', $this->now(), $lastError));

		$stillClaimed = $this->row($message->eventId());

		$this->assertSame('claimed', $stillClaimed['status']);
		$this->assertSame($claimToken, $stillClaimed['claim_token']);

		$this->assertTrue($this->repository->markFailed($record->id, $claimToken, $this->now(), $lastError));

		$row = $this->row($message->eventId());

		$this->assertSame('failed', $row['status']);
		$this->assertSame('2026-11-10 12:00:00', $row['failed_at']);
		$this->assertSame($lastError, $row['last_error']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNull($row['published_at']);
		$this->assertNull($row['next_attempt_at']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['publisher_id']);

		$this->assertSame([], $this->repository->claim(10, $this->now(), 'publisher-b'));
	}

	#[Test]
	public function it_should_fence_terminal_failure_with_the_current_claim_token(): void
	{
		$message = $this->append();
		$firstClaim = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$firstToken = (string) $firstClaim->claimToken;

		$this->update($message->eventId(), [
			'locked_at' => '2026-11-10 11:58:59',
		]);

		$secondClaim = $this->repository->claim(10, $this->now(), 'publisher-b')[0];
		$secondToken = (string) $secondClaim->claimToken;

		$this->assertFalse($this->repository->markFailed($firstClaim->id, $firstToken, $this->now(), 'stale publisher'));

		$stillClaimed = $this->row($message->eventId());

		$this->assertSame('claimed', $stillClaimed['status']);
		$this->assertSame($secondToken, $stillClaimed['claim_token']);

		$this->assertTrue(
			$this->repository->markFailed($secondClaim->id, $secondToken, $this->now(), 'current publisher')
		);

		$row = $this->row($message->eventId());

		$this->assertSame('failed', $row['status']);
		$this->assertSame(2, (int) $row['attempts']);
		$this->assertSame('current publisher', $row['last_error']);
		$this->assertNull($row['claim_token']);
	}

	#[Test]
	public function it_should_transition_an_expired_claim_at_the_attempt_limit_to_failed(): void
	{
		$payload = '{ "sku" : "A-1", "unicode" : "Málaga 🐢" }';
		$message = $this->append(payload: $payload);

		$this->update($message->eventId(), [
			'status' => 'claimed',
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'locked_at' => '2026-11-10 11:58:59',
			'claim_token' => 'old-expired-token',
			'publisher_id' => 'old-publisher',
			'last_error' => null,
		]);

		$this->assertSame([], $this->repository->claim(10, $this->now(), 'publisher-a'));

		$row = $this->row($message->eventId());

		$this->assertSame('failed', $row['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $row['attempts']);
		$this->assertSame('2026-11-10 12:00:00', $row['failed_at']);
		$this->assertStringContainsString(
			sprintf('maximum of %d publication attempts', OutboxRetryPolicy::MAX_ATTEMPTS),
			(string) $row['last_error']
		);
		$this->assertSame($message->eventId(), $row['event_id']);
		$this->assertSame($payload, $row['payload']);
		$this->assertNull($row['published_at']);
		$this->assertNull($row['next_attempt_at']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['publisher_id']);
	}

	#[Test]
	public function it_should_sanitize_a_legacy_error_when_exhaustion_transitions_to_failed(): void
	{
		$message = $this->append();

		$this->update($message->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'next_attempt_at' => '2026-11-10 12:05:00',
			'last_error' => self::SECRET_ERROR,
		]);

		$this->assertSame([], $this->repository->claim(10, $this->now(), 'publisher-a'));

		$row = $this->row($message->eventId());

		$this->assertSame('failed', $row['status']);
		$this->assertStringContainsString('[REDACTED]', (string) $row['last_error']);
		$this->assertStringNotContainsString('s3cr3t-hunter2', (string) $row['last_error']);
		$this->assertStringNotContainsString('guest', (string) $row['last_error']);
	}

	#[Test]
	public function it_should_transition_a_pending_record_at_the_attempt_limit_to_failed(): void
	{
		$payload = '{ "sku" : "A-1", "unicode" : "Málaga 🐢" }';
		$message = $this->append(payload: $payload);
		$lastError = 'OutboxPublicationException: RabbitMQ confirmation timed out.';

		$this->update($message->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'next_attempt_at' => '2026-11-10 12:05:00',
			'last_error' => $lastError,
		]);

		$this->assertSame([], $this->repository->claim(10, $this->now(), 'publisher-a'));

		$row = $this->row($message->eventId());

		$this->assertSame('failed', $row['status']);
		$this->assertSame(OutboxRetryPolicy::MAX_ATTEMPTS, (int) $row['attempts']);
		$this->assertSame('2026-11-10 12:00:00', $row['failed_at']);
		$this->assertSame($lastError, $row['last_error']);
		$this->assertSame($message->eventId(), $row['event_id']);
		$this->assertSame($payload, $row['payload']);
		$this->assertNull($row['published_at']);
		$this->assertNull($row['next_attempt_at']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['publisher_id']);
	}

	#[Test]
	public function it_should_bound_exhausted_record_recovery_by_the_claim_limit(): void
	{
		$oldest = $this->append();
		$middle = $this->append();
		$newest = $this->append();

		$this->update($oldest->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'next_attempt_at' => '2026-11-10 12:05:00',
			'created_at' => '2026-11-10 10:00:00',
		]);
		$this->update($middle->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'next_attempt_at' => '2026-11-10 12:05:00',
			'created_at' => '2026-11-10 10:01:00',
		]);
		$this->update($newest->eventId(), [
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'next_attempt_at' => '2026-11-10 12:05:00',
			'created_at' => '2026-11-10 10:02:00',
		]);

		$this->assertSame([], $this->repository->claim(2, $this->now(), 'publisher-a'));

		$this->assertSame('failed', $this->row($oldest->eventId())['status']);
		$this->assertSame('failed', $this->row($middle->eventId())['status']);
		$this->assertSame('pending', $this->row($newest->eventId())['status']);

		$this->assertSame([], $this->repository->claim(2, $this->now(), 'publisher-a'));

		$this->assertSame('failed', $this->row($newest->eventId())['status']);
	}

	#[Test]
	public function it_should_requeue_a_failed_record_preserving_identity_payload_and_last_error(): void
	{
		$payload = '{ "sku" : "A-1", "unicode" : "Málaga 🐢" }';
		$message = $this->append(payload: $payload);
		$lastError = 'OutboxPublicationException: RabbitMQ confirmation timed out.';

		$this->update($message->eventId(), [
			'status' => 'failed',
			'attempts' => OutboxRetryPolicy::MAX_ATTEMPTS,
			'failed_at' => '2026-11-10 11:55:00',
			'next_attempt_at' => null,
			'locked_at' => '2026-11-10 11:50:00',
			'claim_token' => 'stale-token',
			'publisher_id' => 'stale-publisher',
			'last_error' => $lastError,
		]);

		$this->assertTrue($this->repository->requeueFailed($message->eventId()));

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(0, (int) $row['attempts']);
		$this->assertSame($message->eventId(), $row['event_id']);
		$this->assertSame($payload, $row['payload']);
		$this->assertSame($lastError, $row['last_error']);
		$this->assertSame('2026-11-10 10:34:56', $row['occurred_at']);
		$this->assertNull($row['published_at']);
		$this->assertNull($row['next_attempt_at']);
		$this->assertNull($row['failed_at']);
		$this->assertNull($row['locked_at']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['publisher_id']);

		$this->assertFalse($this->repository->requeueFailed($message->eventId()));
	}

	#[Test]
	public function it_should_prevent_a_stale_claim_from_overwriting_state_after_requeue(): void
	{
		$message = $this->append();
		$record = $this->repository->claim(10, $this->now(), 'publisher-a')[0];
		$claimToken = (string) $record->claimToken;

		$this->assertTrue($this->repository->markFailed($record->id, $claimToken, $this->now(), 'terminal failure'));
		$this->assertTrue($this->repository->requeueFailed($message->eventId()));

		$this->assertFalse($this->repository->markPublished($record->id, $claimToken, $this->now()));
		$this->assertFalse(
			$this->repository->scheduleRetry($record->id, $claimToken, $this->now()->modify('+5 seconds'), 'stale retry')
		);
		$this->assertFalse($this->repository->markFailed($record->id, $claimToken, $this->now(), 'stale failure'));

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(0, (int) $row['attempts']);
		$this->assertSame('terminal failure', $row['last_error']);
		$this->assertNull($row['claim_token']);
		$this->assertNull($row['locked_at']);
	}

	#[Test]
	public function it_should_not_requeue_a_record_that_is_not_failed(): void
	{
		$message = $this->append();

		$this->assertFalse($this->repository->requeueFailed($message->eventId()));

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(0, (int) $row['attempts']);
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

		new MySqlOutboxRepository($this->connection, 0, new OutboxErrorSanitizer());
	}

	#[Test]
	public function it_should_report_status_counts_retrying_records_stale_claims_and_recent_failures(): void
	{
		$this->connection->table('outbox')->delete();

		$pending = $this->append('orders.pending');
		$retrying = $this->append('orders.retrying');
		$activeClaim = $this->append('orders.active');
		$staleClaim = $this->append('orders.stale');
		$published = $this->append('orders.published');
		$failed = $this->append('orders.failed');

		$this->update($pending->eventId(), [
			'created_at' => '2026-11-10 10:00:00',
			'next_attempt_at' => null,
		]);
		$this->update($retrying->eventId(), [
			'created_at' => '2026-11-10 11:00:00',
			'next_attempt_at' => '2026-11-10 12:05:00',
			'attempts' => 1,
		]);
		$this->update($activeClaim->eventId(), [
			'status' => 'claimed',
			'locked_at' => '2026-11-10 11:59:30',
			'claim_token' => 'active-token',
			'publisher_id' => 'publisher-a',
			'attempts' => 1,
		]);
		$this->update($staleClaim->eventId(), [
			'status' => 'claimed',
			'locked_at' => '2026-11-10 11:58:00',
			'claim_token' => 'stale-token',
			'publisher_id' => 'publisher-b',
			'attempts' => 1,
		]);
		$this->update($published->eventId(), [
			'status' => 'published',
			'published_at' => '2026-11-10 11:30:00',
			'attempts' => 1,
		]);
		$this->update($failed->eventId(), [
			'status' => 'failed',
			'failed_at' => '2026-11-10 11:45:00',
			'attempts' => 10,
			'last_error' => 'OutboxPublicationException: RabbitMQ confirmation timed out.',
		]);

		$status = $this->repository->status($this->now());

		$this->assertSame(2, $status->pending);
		$this->assertSame(2, $status->claimed);
		$this->assertSame(1, $status->published);
		$this->assertSame(1, $status->failed);
		$this->assertSame(1, $status->retrying);
		$this->assertSame('2026-11-10 10:00:00', $status->oldestPendingCreatedAt?->format('Y-m-d H:i:s'));
		$this->assertSame(1, $status->staleClaims);
		$this->assertSame(1, $status->activePublishers);
		$this->assertSame('2026-11-10 11:30:00', $status->lastPublishedAt?->format('Y-m-d H:i:s'));
		$this->assertCount(1, $status->recentFailures);
		$this->assertSame($failed->eventId(), $status->recentFailures[0]->eventId);
		$this->assertSame(self::AGGREGATE_ID, $status->recentFailures[0]->aggregateId);
		$this->assertSame('failed', $status->recentFailures[0]->status->value);
		$this->assertSame(10, $status->recentFailures[0]->attempts);
		$this->assertSame(
			'OutboxPublicationException: RabbitMQ confirmation timed out.',
			$status->recentFailures[0]->lastError
		);
	}

	#[Test]
	public function it_should_include_a_pending_retry_in_the_recent_failure_summary(): void
	{
		$this->connection->table('outbox')->delete();

		$retrying = $this->append('orders.retrying');
		$this->update($retrying->eventId(), [
			'next_attempt_at' => '2026-11-10 12:05:00',
			'attempts' => 1,
			'last_error' => 'OutboxPublicationException: connection refused.',
		]);

		$status = $this->repository->status($this->now());

		$this->assertCount(1, $status->recentFailures);
		$this->assertSame($retrying->eventId(), $status->recentFailures[0]->eventId);
		$this->assertSame('pending', $status->recentFailures[0]->status->value);
		$this->assertSame('2026-11-10 12:05:00', $status->recentFailures[0]->nextAttemptAt?->format('Y-m-d H:i:s'));
	}

	#[Test]
	public function it_should_redact_secrets_from_legacy_errors_in_the_status_report(): void
	{
		$this->connection->table('outbox')->delete();

		$failed = $this->append('orders.failed');

		$this->update($failed->eventId(), [
			'status' => 'failed',
			'failed_at' => '2026-11-10 11:45:00',
			'attempts' => 10,
			'last_error' => self::SECRET_ERROR,
		]);

		$status = $this->repository->status($this->now());

		$this->assertCount(1, $status->recentFailures);

		$lastError = $status->recentFailures[0]->lastError;

		$this->assertStringContainsString('[REDACTED]', $lastError);
		$this->assertStringNotContainsString('s3cr3t-hunter2', $lastError);
		$this->assertStringNotContainsString('guest', $lastError);
	}

	#[Test]
	public function it_should_purge_only_published_records_older_than_the_cutoff(): void
	{
		$this->connection->table('outbox')->delete();

		$oldPublished = $this->append('orders.old_published');
		$recentPublished = $this->append('orders.recent_published');
		$oldPending = $this->append('orders.old_pending');
		$oldClaimed = $this->append('orders.old_claimed');
		$oldFailed = $this->append('orders.old_failed');

		$this->update($oldPublished->eventId(), [
			'status' => 'published',
			'published_at' => '2026-11-01 12:00:00',
			'attempts' => 1,
		]);
		$this->update($recentPublished->eventId(), [
			'status' => 'published',
			'published_at' => '2026-11-09 12:00:00',
			'attempts' => 1,
		]);
		$this->update($oldPending->eventId(), [
			'created_at' => '2026-11-01 12:00:00',
		]);
		$this->update($oldClaimed->eventId(), [
			'status' => 'claimed',
			'created_at' => '2026-11-01 12:00:00',
			'locked_at' => '2026-11-01 12:00:00',
			'claim_token' => 'old-token',
			'publisher_id' => 'old-publisher',
			'attempts' => 1,
		]);
		$this->update($oldFailed->eventId(), [
			'status' => 'failed',
			'created_at' => '2026-11-01 12:00:00',
			'failed_at' => '2026-11-01 12:00:00',
			'attempts' => 10,
			'last_error' => 'terminal',
		]);

		$deleted = $this->repository->purgePublishedBefore($this->now()->modify('-7 days'));

		$this->assertSame(1, $deleted);
		$this->assertNull($this->connection->table('outbox')->where('event_id', $oldPublished->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $recentPublished->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldPending->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldClaimed->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldFailed->eventId())->first());
	}

	#[Test]
	public function it_should_reject_a_non_positive_recent_failure_limit(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->repository->status($this->now(), 0);
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
