<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisherLogger;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\CollectingLogger;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxPublisherLoggerTest extends UnitTestCase
{
	private const EVENT_ID = '5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01';
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';
	private const PAYLOAD_MARKER = 'PAYLOAD_SECRET_MARKER';

	#[Test]
	public function it_should_log_a_published_record_without_the_payload(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());

		$publisherLogger->recordPublished($this->record(), $this->now());

		$records = $logger->records();

		$this->assertCount(1, $records);
		$this->assertSame('info', $records[0]['level']);
		$this->assertSame('outbox.record.published', $records[0]['message']);
		$this->assertSame(self::EVENT_ID, $records[0]['context']['event_id']);
		$this->assertSame('orders.order_confirmed', $records[0]['context']['event_name']);
		$this->assertSame('orders', $records[0]['context']['producer']);
		$this->assertSame('order', $records[0]['context']['aggregate_type']);
		$this->assertSame(self::AGGREGATE_ID, $records[0]['context']['aggregate_id']);
		$this->assertSame(2, $records[0]['context']['attempt']);
		$this->assertArrayNotHasKey('payload', $records[0]['context']);
		$this->assertStringNotContainsString(self::PAYLOAD_MARKER, $logger->json());
	}

	#[Test]
	public function it_should_log_a_scheduled_retry_with_the_error_class_and_message(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());
		$nextAttemptAt = $this->now()->modify('+10 seconds');

		$publisherLogger->recordRetryScheduled(
			$this->record(),
			$nextAttemptAt,
			new OutboxPublicationException('RabbitMQ confirmation timed out.')
		);

		$context = $logger->records()[0]['context'];

		$this->assertSame('warning', $logger->records()[0]['level']);
		$this->assertSame('outbox.record.retry_scheduled', $logger->records()[0]['message']);
		$this->assertSame('retry_scheduled', $context['outcome']);
		$this->assertSame('2026-11-11T10:00:10Z', $context['next_attempt_at']);
		$this->assertSame(OutboxPublicationException::class, $context['error_class']);
		$this->assertSame('RabbitMQ confirmation timed out.', $context['error_message']);
		$this->assertStringNotContainsString(self::PAYLOAD_MARKER, $logger->json());
	}

	#[Test]
	public function it_should_log_a_terminal_failure(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());

		$publisherLogger->recordFailed(
			$this->record(attempts: 10),
			new OutboxPublicationException('RabbitMQ negatively acknowledged the integration event.')
		);

		$record = $logger->records()[0];

		$this->assertSame('error', $record['level']);
		$this->assertSame('outbox.record.failed', $record['message']);
		$this->assertSame('failed', $record['context']['outcome']);
		$this->assertSame(10, $record['context']['attempt']);
		$this->assertSame(OutboxPublicationException::class, $record['context']['error_class']);
	}

	#[Test]
	public function it_should_log_a_batch_summary(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());

		$publisherLogger->batchSummary(10, 3, 1, 1, 1);

		$record = $logger->records()[0];

		$this->assertSame('info', $record['level']);
		$this->assertSame('outbox.batch.summary', $record['message']);
		$this->assertSame('completed', $record['context']['outcome']);
		$this->assertSame(10, $record['context']['limit']);
		$this->assertSame(3, $record['context']['handled']);
		$this->assertSame(1, $record['context']['published']);
		$this->assertSame(1, $record['context']['retried']);
		$this->assertSame(1, $record['context']['failed']);
	}

	#[Test]
	public function it_should_log_an_aborted_batch_summary_with_the_error(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());

		$publisherLogger->batchSummary(10, 1, 0, 0, 0, new OutboxPublicationException('Unexpected failure.'));

		$record = $logger->records()[0];

		$this->assertSame('error', $record['level']);
		$this->assertSame('aborted', $record['context']['outcome']);
		$this->assertSame(OutboxPublicationException::class, $record['context']['error_class']);
		$this->assertSame('Unexpected failure.', $record['context']['error_message']);
	}

	#[Test]
	public function it_should_redact_secrets_from_record_and_batch_error_messages(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer());
		$secret = 's3cr3t-hunter2';
		$message = sprintf(
			'Connection amqp://guest:%s@rabbitmq:5672 failed; password=%s token=%s',
			$secret,
			$secret,
			$secret,
		);

		$publisherLogger->recordRetryScheduled(
			$this->record(),
			$this->now()->modify('+10 seconds'),
			new OutboxPublicationException($message),
		);
		$publisherLogger->recordFailed($this->record(attempts: 10), new OutboxPublicationException($message),);
		$publisherLogger->batchSummary(10, 1, 0, 0, 1, new OutboxPublicationException($message),);

		$json = $logger->json();

		$this->assertStringContainsString('[REDACTED]', $json);
		$this->assertStringNotContainsString($secret, $json);
		$this->assertStringNotContainsString('guest', $json);
	}

	#[Test]
	public function it_should_not_log_records_when_logging_is_disabled(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer(), false);

		$publisherLogger->recordPublished($this->record(), $this->now());
		$publisherLogger->recordFailed($this->record(), new OutboxPublicationException('Failure.'));
		$publisherLogger->batchSummary(10, 1, 1, 0, 0);

		$this->assertSame([], $logger->records());
	}

	#[Test]
	public function it_should_not_log_a_batch_summary_when_the_summary_switch_is_disabled(): void
	{
		$logger = new CollectingLogger();
		$publisherLogger = new OutboxPublisherLogger($logger, new OutboxErrorSanitizer(), true, false);

		$publisherLogger->recordPublished($this->record(), $this->now());
		$publisherLogger->batchSummary(10, 1, 1, 0, 0);

		$this->assertCount(1, $logger->records());
		$this->assertSame('outbox.record.published', $logger->records()[0]['message']);
	}

	private function record(int $attempts = 2): OutboxRecord
	{
		return new OutboxRecord(
			id: '2f7f34f8-1f8b-4a4f-8f57-0fda4f9a5e01',
			producer: 'orders',
			eventId: self::EVENT_ID,
			eventName: 'orders.order_confirmed',
			eventVersion: '1',
			aggregateType: 'order',
			aggregateId: self::AGGREGATE_ID,
			aggregateVersion: 7,
			correlationId: 'correlation-1',
			causationId: 'causation-1',
			payload: sprintf('{"orderId":"abc","secret":"%s"}', self::PAYLOAD_MARKER),
			occurredAt: new DateTimeImmutable('2026-11-10T10:34:56Z', new DateTimeZone('UTC')),
			createdAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			publishedAt: null,
			attempts: $attempts,
			nextAttemptAt: null,
			lockedAt: new DateTimeImmutable('2026-11-10T10:35:01Z', new DateTimeZone('UTC')),
			claimToken: 'claim-token',
			publisherId: 'publisher-1',
			lastError: null,
			status: OutboxStatus::CLAIMED,
			failedAt: null,
		);
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-11-11 10:00:00', new DateTimeZone('UTC'));
	}
}
