<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Infrastructure\Outbox\OutboxClaimLostException;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxEventPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
	public function it_should_leave_the_record_unpublished_when_the_publication_fails(): void
	{
		$repository = new FakeOutboxRepository([$this->record()]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ negatively acknowledged the integration event.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should propagate the publication failure.');
		} catch (OutboxPublicationException $exception) {
			$this->assertStringContainsString('negatively acknowledged', $exception->getMessage());
		}

		$this->assertSame([1], $repository->claimLimits);
		$this->assertSame([], $repository->published);
		$this->assertSame([], $transport->published);
	}

	#[Test]
	public function it_should_propagate_unexpected_errors_without_marking_the_record_as_published(): void
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
		$this->assertSame([], $transport->published);
	}

	#[Test]
	public function it_should_stop_the_batch_when_the_publication_fails(): void
	{
		$first = $this->record('11111111-1111-4111-8111-111111111111');
		$second = $this->record('22222222-2222-4222-8222-222222222222');
		$repository = new FakeOutboxRepository([$first, $second]);
		$transport = new ScriptedOutboxEventPublisher([
			new OutboxPublicationException('RabbitMQ confirmation timed out.'),
		]);
		$publisher = $this->publisher($repository, $transport);

		try {
			$publisher->publishBatch(10);
			$this->fail('The publisher should propagate the publication failure.');
		} catch (OutboxPublicationException $exception) {
			$this->assertStringContainsString('timed out', $exception->getMessage());
		}

		$this->assertSame([1], $repository->claimLimits);
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

	private function publisher(OutboxRepository $repository, OutboxEventPublisher $transport): OutboxPublisher
	{
		return new OutboxPublisher($repository, new OutboxEnvelopeSerializer(), $transport, 'publisher-test');
	}

	private function record(string $eventId = self::EVENT_ID, ?string $claimToken = 'claim-token'): OutboxRecord
	{
		return new OutboxRecord(
			id: $eventId === self::EVENT_ID
				? '2f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e01'
				: '3f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e02',
			producer: 'orders',
			eventId: $eventId,
			eventName: 'orders.order_confirmed',
			eventVersion: '1',
			aggregateType: 'order',
			aggregateId: self::AGGREGATE_ID,
			aggregateVersion: 7,
			correlationId: 'correlation-1',
			causationId: 'causation-1',
			payload: '{"orderId":"abc","total":19.90}',
			occurredAt: new DateTimeImmutable('2026-11-10T10:34:56Z', new DateTimeZone('UTC')),
			createdAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			publishedAt: null,
			attempts: 1,
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

	/** @var list<int> */
	public array $claimLimits = [];

	public bool $markPublishedResult = true;

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
}

final class ScriptedOutboxEventPublisher implements OutboxEventPublisher
{
	/** @var list<array{eventId: string, envelope: string}> */
	public array $published = [];

	/** @param list<Throwable> $failures */
	public function __construct(private array $failures = []) {}

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
