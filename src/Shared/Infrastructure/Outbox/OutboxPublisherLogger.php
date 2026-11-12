<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class OutboxPublisherLogger
{
	public function __construct(
		private LoggerInterface $logger,
		private OutboxErrorSanitizer $errorSanitizer,
		private bool $enabled = true,
		private bool $batchSummaryEnabled = true,
	) {}

	public function recordPublished(OutboxRecord $record, DateTimeImmutable $publishedAt): void
	{
		$this->logRecord('info', 'outbox.record.published', $record, [
			'outcome' => 'published',
			'published_at' => $this->formatDate($publishedAt),
		]);
	}

	public function recordRetryScheduled(
		OutboxRecord $record,
		DateTimeImmutable $nextAttemptAt,
		Throwable $error,
	): void {
		$this->logRecord('warning', 'outbox.record.retry_scheduled', $record, [
			'outcome' => 'retry_scheduled',
			'next_attempt_at' => $this->formatDate($nextAttemptAt),
			'error_class' => $error::class,
			'error_message' => $this->sanitizedMessage($error),
		]);
	}

	public function recordFailed(OutboxRecord $record, Throwable $error): void
	{
		$this->logRecord('error', 'outbox.record.failed', $record, [
			'outcome' => 'failed',
			'error_class' => $error::class,
			'error_message' => $this->sanitizedMessage($error),
		]);
	}

	public function batchSummary(
		int $limit,
		int $handled,
		int $published,
		int $retried,
		int $failed,
		?Throwable $error = null,
	): void {
		if (!$this->enabled || !$this->batchSummaryEnabled) {
			return;
		}

		$context = [
			'outcome' => $error === null ? 'completed' : 'aborted',
			'limit' => $limit,
			'handled' => $handled,
			'published' => $published,
			'retried' => $retried,
			'failed' => $failed,
		];

		if ($error !== null) {
			$context['error_class'] = $error::class;
			$context['error_message'] = $this->sanitizedMessage($error);
		}

		$this->logger->log($error === null ? 'info' : 'error', 'outbox.batch.summary', $context);
	}

	/** @param array<string, mixed> $context */
	private function logRecord(string $level, string $message, OutboxRecord $record, array $context): void
	{
		if (!$this->enabled) {
			return;
		}

		$this->logger->log($level, $message, array_merge([
			'event_id' => $record->eventId,
			'event_name' => $record->eventName,
			'producer' => $record->producer,
			'aggregate_type' => $record->aggregateType,
			'aggregate_id' => $record->aggregateId,
			'attempt' => $record->attempts,
			'publisher_id' => $record->publisherId,
			'correlation_id' => $record->correlationId,
			'causation_id' => $record->causationId,
		], $context));
	}

	private function sanitizedMessage(Throwable $error): string
	{
		return $this->errorSanitizer->sanitize($error->getMessage());
	}

	private function formatDate(DateTimeImmutable $date): string
	{
		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}
}
