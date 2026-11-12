<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Application\Outbox\OutboxStatusSnapshot;
use Manager\Shared\Infrastructure\Outbox\OutboxClaimLostException;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Manager\Shared\Infrastructure\Outbox\OutboxEventPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisherLogger;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;
use Manager\Shared\Infrastructure\Outbox\OutboxRetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Shared\Infrastructure\PhpUnit\CollectingLogger;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;
use Throwable;

final class OutboxPublisherTest extends UnitTestCase
{
	private const EVENT_ID = '5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01';
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	#[Test]
	public function it_should_publish_and_mark_the_record_as_published(): void
	{
		$record = $this->record();
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(10);

		$this->assertSame(1, $processed);
		$this->assertCount(1, $transport->published);
		$this->assertSame(self::EVENT_ID, $transport->published[0]['eventId']);
		$this->assertSame((new OutboxEnvelopeSerializer())->serialize($record), $transport->published[0]['envelope']);
		$this->assertCount(1, $repository->published);
		$this->assertSame($record->id, $repository->published[0]['recordId']);
		$this->assertSame('claim-token', $repository->published[0]['claimToken']);
		$this->assertSame([], $repository->retries);
		$this->assertSame([], $repository->failures);
	}

	#[Test]
	public function it_should_log_a_retry_and_a_batch_summary_without_payload_values(): void
	{
		$record = $this->record(payload: '{"orderId":"abc","secret":"PAYLOAD_SECRET_MARKER"}');
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$logger = new CollectingLogger();

		$this->publisher($repository, $transport, $logger)->publishBatch(10);

		$records = $logger->records();

		$this->assertCount(2, $records);
		$this->assertSame('outbox.record.retry_scheduled', $records[0]['message']);
		$this->assertSame(self::EVENT_ID, $records[0]['context']['event_id']);
		$this->assertSame(OutboxPublicationException::class, $records[0]['context']['error_class']);
		$this->assertSame('outbox.batch.summary', $records[1]['message']);
		$this->assertSame(1, $records[1]['context']['retried']);
		$this->assertStringNotContainsString('PAYLOAD_SECRET_MARKER', $logger->json());
	}

	#[Test]
	public function it_should_claim_records_one_at_a_time_so_the_lease_covers_a_single_publication(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(10);

		$this->assertSame(2, $processed);
		$this->assertSame([1, 1, 1], $repository->claimLimits);
		$this->assertCount(2, $repository->published);
		$this->assertCount(2, $transport->published);
	}

	#[Test]
	public function it_should_not_process_more_records_than_the_requested_limit(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(1);

		$this->assertSame(1, $processed);
		$this->assertSame([1], $repository->claimLimits);
		$this->assertSame($first->id, $repository->published[0]['recordId']);
		$this->assertCount(1, $transport->published);
	}

	#[Test]
	public function it_should_schedule_a_retry_when_publication_fails_below_the_attempt_limit(): void
	{
		$record = $this->record();
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ negatively acknowledged the integration event.'),
		]);
		$publisher = $this->publisher($repository, $transport);
		$before = time();

		$processed = $publisher->publishBatch(10);
		$after = time();

		$this->assertSame(0, $processed);
		$this->assertSame([], $repository->published);
		$this->assertCount(1, $repository->retries);
		$this->assertSame($record->id, $repository->retries[0]['recordId']);
		$this->assertSame('claim-token', $repository->retries[0]['claimToken']);
		$this->assertStringContainsString('negatively acknowledged', $repository->retries[0]['lastError']);
		$this->assertGreaterThanOrEqual($before + 5, $repository->retries[0]['nextAttemptAt']->getTimestamp());
		$this->assertLessThanOrEqual($after + 5, $repository->retries[0]['nextAttemptAt']->getTimestamp());
		$this->assertSame([], $repository->failures);
		$this->assertSame([], $transport->failures);
		$this->assertSame([], $transport->published);
	}

	#[Test]
	public function it_should_sanitize_secrets_before_persisting_and_logging_a_retry_error(): void
	{
		$secret = 's3cr3t-hunter2';
		$record = $this->record();
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException(sprintf(
				'Connection amqp://guest:%s@rabbitmq:5672 failed; password=%s token=%s',
				$secret,
				$secret,
				$secret,
			)),
		]);
		$logger = new CollectingLogger();

		$this->publisher($repository, $transport, $logger)->publishBatch(10);

		$this->assertCount(1, $repository->retries);

		$lastError = $repository->retries[0]['lastError'];

		$this->assertStringContainsString(OutboxPublicationException::class, $lastError);
		$this->assertStringContainsString('[REDACTED]', $lastError);
		$this->assertStringNotContainsString($secret, $lastError);
		$this->assertStringNotContainsString('guest', $lastError);
		$this->assertStringNotContainsString($secret, $logger->json());
		$this->assertStringNotContainsString('guest', $logger->json());
	}

	#[Test]
	public function it_should_sanitize_secrets_before_persisting_a_terminal_failure(): void
	{
		$secret = 's3cr3t-hunter2';
		$record = $this->record(attempts: 10);
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException(sprintf('password=%s token=%s', $secret, $secret)),
		]);

		$this->publisher($repository, $transport)->publishBatch(10);

		$this->assertCount(1, $repository->failures);

		$lastError = $repository->failures[0]['lastError'];

		$this->assertStringContainsString(OutboxPublicationException::class, $lastError);
		$this->assertStringContainsString('[REDACTED]', $lastError);
		$this->assertStringNotContainsString($secret, $lastError);
	}

	#[Test]
	public function it_should_mark_the_record_as_failed_when_the_tenth_attempt_fails(): void
	{
		$record = $this->record(attempts: 10);
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(10);

		$this->assertSame(0, $processed);
		$this->assertSame([], $repository->published);
		$this->assertSame([], $repository->retries);
		$this->assertCount(1, $repository->failures);
		$this->assertSame($record->id, $repository->failures[0]['recordId']);
		$this->assertSame('claim-token', $repository->failures[0]['claimToken']);
		$this->assertStringContainsString('timed out', $repository->failures[0]['lastError']);
	}

	#[Test]
	public function it_should_mark_the_record_as_failed_without_retry_when_serialization_fails(): void
	{
		$record = $this->record(eventName: "\xB1\x31");
		$repository = new FakeOutboxRepository([$record]);
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(10);

		$this->assertSame(0, $processed);
		$this->assertSame([], $repository->published);
		$this->assertSame([], $repository->retries);
		$this->assertCount(1, $repository->failures);
		$this->assertStringContainsString('could not be serialized', $repository->failures[0]['lastError']);
		$this->assertSame([], $transport->published);
		$this->assertSame([], $transport->failures);
	}

	#[Test]
	public function it_should_propagate_unexpected_errors_without_transitioning_the_record(): void
	{
		$repository = new FakeOutboxRepository([$this->record()]);
		$transport = new ScriptedOutboxEventPublisher([new LogicException('Unexpected failure.'), ]);
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should propagate unexpected errors.');
		} catch (LogicException $exception) {
			$this->assertSame('Unexpected failure.', $exception->getMessage());
		}

		$this->assertSame([1], $repository->claimLimits);
		$this->assertSame([], $repository->published);
		$this->assertSame([], $repository->retries);
		$this->assertSame([], $repository->failures);
		$this->assertSame([], $transport->published);
	}

	#[Test]
	public function it_should_continue_with_the_next_record_after_scheduling_a_retry(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(2);

		$this->assertSame(1, $processed);
		$this->assertSame([1, 1], $repository->claimLimits);
		$this->assertCount(1, $repository->retries);
		$this->assertSame($first->id, $repository->retries[0]['recordId']);
		$this->assertCount(1, $repository->published);
		$this->assertSame($second->id, $repository->published[0]['recordId']);
		$this->assertCount(1, $transport->published);
		$this->assertSame($second->eventId, $transport->published[0]['eventId']);
	}

	#[Test]
	public function it_should_count_a_scheduled_retry_towards_the_requested_limit(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		$processed = $publisher->publishBatch(1);

		$this->assertSame(0, $processed);
		$this->assertSame([1], $repository->claimLimits);
		$this->assertCount(1, $repository->retries);
		$this->assertSame([], $repository->published);
		$this->assertSame([], $transport->published);
	}

	#[Test]
	public function it_should_signal_a_lost_claim_and_stop_the_batch_when_mark_published_fails(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$repository->markPublishedResult = false;
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should signal the lost claim.');
		} catch (OutboxClaimLostException $exception) {
			$this->assertStringContainsString($first->id, $exception->getMessage());
		}

		$this->assertSame([1], $repository->claimLimits);
		$this->assertCount(1, $repository->published);
		$this->assertCount(1, $transport->published);
		$this->assertSame([], $repository->retries);
		$this->assertSame([], $repository->failures);
	}

	#[Test]
	public function it_should_signal_a_lost_claim_when_the_retry_cannot_be_scheduled(): void
	{
		$repository = new FakeOutboxRepository([$this->record()]);
		$repository->scheduleRetryResult = false;
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should signal the lost claim.');
		} catch (OutboxClaimLostException $exception) {
			$this->assertStringContainsString('retry could be scheduled', $exception->getMessage());
		}

		$this->assertSame([], $repository->published);
		$this->assertCount(1, $repository->retries);
		$this->assertSame([], $repository->failures);
	}

	#[Test]
	public function it_should_signal_a_lost_claim_when_the_terminal_failure_cannot_be_confirmed(): void
	{
		$repository = new FakeOutboxRepository([$this->record(attempts: 10)]);
		$repository->markFailedResult = false;
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should signal the lost claim.');
		} catch (OutboxClaimLostException $exception) {
			$this->assertStringContainsString('marked as failed', $exception->getMessage());
		}

		$this->assertSame([], $repository->published);
		$this->assertSame([], $repository->retries);
		$this->assertCount(1, $repository->failures);
	}

	#[Test]
	public function it_should_reject_a_claimed_record_without_a_claim_token(): void
	{
		$repository = new FakeOutboxRepository([$this->record(claimToken: null)]);
		$transport = new ScriptedOutboxEventPublisher();
		$publisher = $this->publisher($repository, $transport);

		$this->expectException(RuntimeException::class);

		$publisher->publishBatch(10);
	}

	#[Test]
	public function it_should_reject_a_non_positive_limit(): void
	{
		$repository = new FakeOutboxRepository([]);

		$this->expectException(InvalidArgumentException::class);

		$this->publisher($repository, new ScriptedOutboxEventPublisher())->publishBatch(0);
	}

	private function publisher(
		OutboxRepository $repository,
		OutboxEventPublisher $transport,
		?CollectingLogger $logger = null,
	): OutboxPublisher {
		return new OutboxPublisher(
			$repository,
			new OutboxEnvelopeSerializer(),
			$transport,
			new OutboxRetryPolicy(),
			new OutboxPublisherLogger($logger ?? new NullLogger(), new OutboxErrorSanitizer()),
			new OutboxErrorSanitizer(),
			'publisher-test'
		);
	}

	private function record(
		string $eventId = self::EVENT_ID,
		?string $claimToken = 'claim-token',
		int $attempts = 1,
		string $eventName = 'orders.order_confirmed',
		string $payload = '{"orderId":"abc","total":19.90}',
	): OutboxRecord {
		return new OutboxRecord(
			id: $eventId === self::EVENT_ID
				? '2f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e01'
				: '3f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e02',
			producer: 'orders',
			eventId: $eventId,
			eventName: $eventName,
			eventVersion: '1',
			aggregateType: 'order',
			aggregateId: self::AGGREGATE_ID,
			aggregateVersion: 7,
			correlationId: 'correlation-1',
			causationId: 'causation-1',
			payload: $payload,
			occurredAt: new DateTimeImmutable('2026-11-10T10:34:56Z', new DateTimeZone('UTC')),
			createdAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			publishedAt: null,
			attempts: $attempts,
			nextAttemptAt: null,
			lockedAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			claimToken: $claimToken,
			publisherId: 'publisher-1',
			lastError: null,
			status: OutboxStatus::CLAIMED,
			failedAt: null,
		);
	}
}

final class FakeOutboxRepository implements OutboxRepository
{
	/** @var list<array{recordId: string, claimToken: string, publishedAt: DateTimeImmutable}> */
	public array $published = [];

	/** @var list<array{recordId: string, claimToken: string, nextAttemptAt: DateTimeImmutable, lastError: string}> */
	public array $retries = [];

	/** @var list<array{recordId: string, claimToken: string, failedAt: DateTimeImmutable, lastError: string}> */
	public array $failures = [];

	/** @var list<int> */
	public array $claimLimits = [];

	public bool $markPublishedResult = true;

	public bool $scheduleRetryResult = true;

	public bool $markFailedResult = true;

	/** @var list<OutboxRecord> */
	private array $records;

	/** @param list<OutboxRecord> $records */
	public function __construct(array $records)
	{
		$this->records = $records;
	}

	public function claim(int $limit, DateTimeImmutable $now, string $publisherId): array
	{
		$this->claimLimits[] = $limit;

		/** @var list<OutboxRecord> $claimed */
		$claimed = array_splice($this->records, 0, $limit);

		return $claimed;
	}

	public function markPublished(string $recordId, string $claimToken, DateTimeImmutable $publishedAt): bool
	{
		$this->published[] = [
			'recordId' => $recordId,
			'claimToken' => $claimToken,
			'publishedAt' => $publishedAt,
		];

		return $this->markPublishedResult;
	}

	public function scheduleRetry(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $nextAttemptAt,
		string $lastError,
	): bool {
		$this->retries[] = [
			'recordId' => $recordId,
			'claimToken' => $claimToken,
			'nextAttemptAt' => $nextAttemptAt,
			'lastError' => $lastError,
		];

		return $this->scheduleRetryResult;
	}

	public function markFailed(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $failedAt,
		string $lastError,
	): bool {
		$this->failures[] = [
			'recordId' => $recordId,
			'claimToken' => $claimToken,
			'failedAt' => $failedAt,
			'lastError' => $lastError,
		];

		return $this->markFailedResult;
	}

	public function requeueFailed(string $eventId): bool
	{
		return false;
	}

	public function status(DateTimeImmutable $now, int $recentFailureLimit = 10): OutboxStatusSnapshot
	{
		return new OutboxStatusSnapshot(
			pending: 0,
			claimed: 0,
			published: 0,
			failed: 0,
			retrying: 0,
			oldestPendingCreatedAt: null,
			staleClaims: 0,
			activePublishers: 0,
			lastPublishedAt: null,
			recentFailures: [],
		);
	}

	public function purgePublishedBefore(DateTimeImmutable $cutoff): int
	{
		return 0;
	}
}

final class ScriptedOutboxEventPublisher implements OutboxEventPublisher
{
	/** @var list<array{eventId: string, envelope: string}> */
	public array $published = [];

	/** @var list<Throwable> */
	public array $failures;

	/** @param list<Throwable> $failures */
	public function __construct(array $failures = [])
	{
		$this->failures = $failures;
	}

	public function publish(OutboxRecord $record, string $envelope): void
	{
		$failure = array_shift($this->failures);

		if ($failure !== null) {
			throw $failure;
		}

		$this->published[] = [
			'eventId' => $record->eventId,
			'envelope' => $envelope,
		];
	}
}
