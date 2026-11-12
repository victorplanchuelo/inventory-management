<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class OutboxPublisher
{
	/**
	 * Claims are processed one at a time so the repository lease only has to
	 * cover a single publication attempt. Claiming a whole batch up front
	 * would let the lease expire before the last record is published.
	 */
	private const CLAIM_BATCH_SIZE = 1;

	public function __construct(
		private OutboxRepository $repository,
		private OutboxEnvelopeSerializer $serializer,
		private OutboxEventPublisher $publisher,
		private OutboxRetryPolicy $retryPolicy,
		private OutboxPublisherLogger $logger,
		private OutboxErrorSanitizer $errorSanitizer,
		private string $publisherId,
	) {
		if (trim($this->publisherId) === '') {
			throw new InvalidArgumentException('The Outbox publisher identifier must not be empty.');
		}
	}

	public function publishBatch(int $limit): int
	{
		if ($limit < 1) {
			throw new InvalidArgumentException('The Outbox publish limit must be a positive integer.');
		}

		$published = 0;
		$retried = 0;
		$failed = 0;
		$handled = 0;

		try {
			while ($handled < $limit) {
				$records = $this->repository->claim(self::CLAIM_BATCH_SIZE, $this->now(), $this->publisherId);

				if ($records === []) {
					break;
				}

				foreach ($records as $record) {
					if ($handled >= $limit) {
						break;
					}

					$handled++;

					$claimToken = $record->claimToken;

					if ($claimToken === null) {
						throw new RuntimeException(sprintf('The claimed Outbox record <%s> has no claim token.', $record->id));
					}

					$result = $this->publishRecord($record, $claimToken);

					match ($result) {
						OutboxPublicationResult::PUBLISHED => $published++,
						OutboxPublicationResult::RETRY_SCHEDULED => $retried++,
						OutboxPublicationResult::FAILED => $failed++,
					};
				}
			}
		} catch (Throwable $exception) {
			$this->logger->batchSummary($limit, $handled, $published, $retried, $failed, $exception);

			throw $exception;
		}

		$this->logger->batchSummary($limit, $handled, $published, $retried, $failed);

		return $published;
	}

	private function publishRecord(OutboxRecord $record, string $claimToken): OutboxPublicationResult
	{
		try {
			$envelope = $this->serializer->serialize($record);
		} catch (OutboxSerializationException $exception) {
			return $this->markTerminal($record, $claimToken, $exception);
		}

		try {
			$this->publisher->publish($record, $envelope);
		} catch (OutboxPublicationException $exception) {
			return $this->handlePublicationFailure($record, $claimToken, $exception);
		}

		$publishedAt = $this->now();

		if (!$this->repository->markPublished($record->id, $claimToken, $publishedAt)) {
			throw new OutboxClaimLostException(
				sprintf('The Outbox claim for record <%s> was lost before it could be marked as published.', $record->id)
			);
		}

		$this->logger->recordPublished($record, $publishedAt);

		return OutboxPublicationResult::PUBLISHED;
	}

	private function handlePublicationFailure(
		OutboxRecord $record,
		string $claimToken,
		OutboxPublicationException $exception,
	): OutboxPublicationResult {
		$now = $this->now();
		$lastError = $this->describeError($exception);

		if ($this->retryPolicy->isTerminal($record->attempts)) {
			$this->markFailed($record, $claimToken, $now, $lastError);
			$this->logger->recordFailed($record, $exception);

			return OutboxPublicationResult::FAILED;
		}

		$nextAttemptAt = $this->retryPolicy->nextAttemptAt($now, $record->attempts);

		$scheduled = $this->repository->scheduleRetry($record->id, $claimToken, $nextAttemptAt, $lastError);

		if (!$scheduled) {
			throw new OutboxClaimLostException(
				sprintf('The Outbox claim for record <%s> was lost before its retry could be scheduled.', $record->id)
			);
		}

		$this->logger->recordRetryScheduled($record, $nextAttemptAt, $exception);

		return OutboxPublicationResult::RETRY_SCHEDULED;
	}

	private function markTerminal(
		OutboxRecord $record,
		string $claimToken,
		Throwable $exception,
	): OutboxPublicationResult {
		$this->markFailed($record, $claimToken, $this->now(), $this->describeError($exception));
		$this->logger->recordFailed($record, $exception);

		return OutboxPublicationResult::FAILED;
	}

	private function markFailed(
		OutboxRecord $record,
		string $claimToken,
		DateTimeImmutable $failedAt,
		string $lastError,
	): void {
		if ($this->repository->markFailed($record->id, $claimToken, $failedAt, $lastError)) {
			return;
		}

		throw new OutboxClaimLostException(
			sprintf('The Outbox claim for record <%s> was lost before it could be marked as failed.', $record->id)
		);
	}

	private function describeError(Throwable $exception): string
	{
		$message = trim($this->errorSanitizer->sanitize($exception->getMessage()));

		if ($message === '') {
			return $exception::class;
		}

		return sprintf('%s: %s', $exception::class, $message);
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
