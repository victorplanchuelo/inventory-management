<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxFailure;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Application\Outbox\OutboxStatusSnapshot;
use RuntimeException;

final readonly class MySqlOutboxRepository implements OutboxRepository
{
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		private Connection $connection,
		private int $leaseSeconds,
		private OutboxErrorSanitizer $errorSanitizer,
		private int $maxAttempts = OutboxRetryPolicy::MAX_ATTEMPTS,
	) {
		if ($this->leaseSeconds < 1) {
			throw new InvalidArgumentException('The Outbox lease must be at least one second.');
		}

		if ($this->maxAttempts < 1) {
			throw new InvalidArgumentException('The Outbox attempt limit must be a positive integer.');
		}
	}

	public function claim(int $limit, DateTimeImmutable $now, string $publisherId): array
	{
		if ($limit < 1) {
			throw new InvalidArgumentException('The Outbox claim limit must be a positive integer.');
		}

		/** @var list<OutboxRecord> $records */
		$records = $this->connection->transaction(
			fn (): array => $this->claimWithinTransaction($limit, $now, $publisherId)
		);

		return $records;
	}

	public function markPublished(string $recordId, string $claimToken, DateTimeImmutable $publishedAt): bool
	{
		$updated = $this->connection->table('outbox')
			->where('id', $recordId)
			->where('claim_token', $claimToken)
			->where('status', OutboxStatus::CLAIMED->value)
			->update([
				'status' => OutboxStatus::PUBLISHED->value,
				'published_at' => $this->formatDate($publishedAt),
				'next_attempt_at' => null,
				'locked_at' => null,
				'claim_token' => null,
				'publisher_id' => null,
				'last_error' => null,
				'failed_at' => null,
			]);

		return $updated === 1;
	}

	public function scheduleRetry(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $nextAttemptAt,
		string $lastError,
	): bool {
		$lastError = $this->errorSanitizer->sanitize($lastError);

		$updated = $this->connection->table('outbox')
			->where('id', $recordId)
			->where('claim_token', $claimToken)
			->where('status', OutboxStatus::CLAIMED->value)
			->where('attempts', '<', $this->maxAttempts)
			->update([
				'status' => OutboxStatus::PENDING->value,
				'published_at' => null,
				'next_attempt_at' => $this->formatDate($nextAttemptAt),
				'locked_at' => null,
				'claim_token' => null,
				'publisher_id' => null,
				'last_error' => $lastError,
				'failed_at' => null,
			]);

		return $updated === 1;
	}

	public function markFailed(
		string $recordId,
		string $claimToken,
		DateTimeImmutable $failedAt,
		string $lastError,
	): bool {
		$lastError = $this->errorSanitizer->sanitize($lastError);

		$updated = $this->connection->table('outbox')
			->where('id', $recordId)
			->where('claim_token', $claimToken)
			->where('status', OutboxStatus::CLAIMED->value)
			->update([
				'status' => OutboxStatus::FAILED->value,
				'published_at' => null,
				'next_attempt_at' => null,
				'failed_at' => $this->formatDate($failedAt),
				'locked_at' => null,
				'claim_token' => null,
				'publisher_id' => null,
				'last_error' => $lastError,
			]);

		return $updated === 1;
	}

	public function requeueFailed(string $eventId): bool
	{
		$updated = $this->connection->table('outbox')
			->where('event_id', $eventId)
			->where('status', OutboxStatus::FAILED->value)
			->update([
				'status' => OutboxStatus::PENDING->value,
				'attempts' => 0,
				'published_at' => null,
				'next_attempt_at' => null,
				'failed_at' => null,
				'locked_at' => null,
				'claim_token' => null,
				'publisher_id' => null,
			]);

		return $updated === 1;
	}

	public function status(DateTimeImmutable $now, int $recentFailureLimit = 10): OutboxStatusSnapshot
	{
		if ($recentFailureLimit < 1) {
			throw new InvalidArgumentException('The Outbox recent failure limit must be a positive integer.');
		}

		$now = $now->setTimezone(new DateTimeZone('UTC'));
		$nowValue = $this->formatDate($now);
		$staleBefore = $this->formatDate($now->modify(sprintf('-%d seconds', $this->leaseSeconds)));

		$row = (array) $this->connection->selectOne(
			'SELECT
				COALESCE(SUM(status = ?), 0) AS pending_count,
				COALESCE(SUM(status = ?), 0) AS claimed_count,
				COALESCE(SUM(status = ?), 0) AS published_count,
				COALESCE(SUM(status = ?), 0) AS failed_count,
				COALESCE(SUM(status = ? AND next_attempt_at IS NOT NULL AND next_attempt_at > ?), 0) AS retrying_count,
				MIN(CASE WHEN status = ? THEN created_at END) AS oldest_pending_created_at,
				COALESCE(SUM(status = ? AND locked_at IS NOT NULL AND locked_at <= ?), 0) AS stale_claims,
				COUNT(DISTINCT CASE WHEN status = ? AND locked_at > ? THEN publisher_id END) AS active_publishers,
				MAX(published_at) AS last_published_at
			FROM outbox',
			[
				OutboxStatus::PENDING->value,
				OutboxStatus::CLAIMED->value,
				OutboxStatus::PUBLISHED->value,
				OutboxStatus::FAILED->value,
				OutboxStatus::PENDING->value,
				$nowValue,
				OutboxStatus::PENDING->value,
				OutboxStatus::CLAIMED->value,
				$staleBefore,
				OutboxStatus::CLAIMED->value,
				$staleBefore,
			]
		);

		return new OutboxStatusSnapshot(
			pending: (int) ($row['pending_count'] ?? 0),
			claimed: (int) ($row['claimed_count'] ?? 0),
			published: (int) ($row['published_count'] ?? 0),
			failed: (int) ($row['failed_count'] ?? 0),
			retrying: (int) ($row['retrying_count'] ?? 0),
			oldestPendingCreatedAt: $this->optionalDate($row['oldest_pending_created_at'] ?? null),
			staleClaims: (int) ($row['stale_claims'] ?? 0),
			activePublishers: (int) ($row['active_publishers'] ?? 0),
			lastPublishedAt: $this->optionalDate($row['last_published_at'] ?? null),
			recentFailures: $this->recentFailures($recentFailureLimit),
		);
	}

	public function purgePublishedBefore(DateTimeImmutable $cutoff): int
	{
		return (int) $this->connection->table('outbox')
			->where('status', OutboxStatus::PUBLISHED->value)
			->whereNotNull('published_at')
			->where('published_at', '<', $this->formatDate($cutoff))
			->delete();
	}

	/** @return list<OutboxRecord> */
	private function claimWithinTransaction(int $limit, DateTimeImmutable $now, string $publisherId): array
	{
		$now = $now->setTimezone(new DateTimeZone('UTC'));
		$nowValue = $this->formatDate($now);
		$expiredBefore = $this->formatDate($now->modify(sprintf('-%d seconds', $this->leaseSeconds)));

		$this->failExhaustedRecords($limit, $nowValue, $expiredBefore);

		$rows = $this->connection->select(
			sprintf(
				'SELECT * FROM outbox
				WHERE (
						(status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) AND attempts < ?)
						OR (status = ? AND locked_at IS NOT NULL AND locked_at <= ? AND attempts < ?)
					)
				ORDER BY created_at ASC, id ASC
				LIMIT %d
				FOR UPDATE SKIP LOCKED',
				$limit
			),
			[
				OutboxStatus::PENDING->value,
				$nowValue,
				$this->maxAttempts,
				OutboxStatus::CLAIMED->value,
				$expiredBefore,
				$this->maxAttempts,
			]
		);

		if ($rows === []) {
			return [];
		}

		$recordIds = [];

		foreach ($rows as $row) {
			$values = (array) $row;
			$recordId = (string) $values['id'];
			$recordIds[] = $recordId;

			$updated = $this->connection->table('outbox')
				->where('id', $recordId)
				->update([
					'status' => OutboxStatus::CLAIMED->value,
					'locked_at' => $nowValue,
					'claim_token' => $this->claimToken(),
					'publisher_id' => mb_substr($publisherId, 0, 128),
					'attempts' => ((int) $values['attempts']) + 1,
					'next_attempt_at' => null,
				]);

			if ($updated !== 1) {
				throw new RuntimeException(sprintf('The Outbox record <%s> could not be claimed.', $recordId));
			}
		}

		$claimedRows = $this->connection->table('outbox')
			->whereIn('id', $recordIds)
			->orderBy('created_at')
			->orderBy('id')
			->get();

		$records = [];

		foreach ($claimedRows as $row) {
			$records[] = OutboxRecord::fromDatabaseRow((array) $row);
		}

		return $records;
	}

	private function failExhaustedRecords(int $limit, string $nowValue, string $expiredBefore): void
	{
		$rows = $this->connection->select(
			sprintf(
				'SELECT id, status, claim_token, last_error FROM outbox
				WHERE attempts >= ?
					AND (
						status = ?
						OR (status = ? AND locked_at IS NOT NULL AND locked_at <= ?)
					)
				ORDER BY created_at ASC, id ASC
				LIMIT %d
				FOR UPDATE SKIP LOCKED',
				$limit
			),
			[$this->maxAttempts, OutboxStatus::PENDING->value, OutboxStatus::CLAIMED->value, $expiredBefore, ]
		);

		foreach ($rows as $row) {
			$values = (array) $row;
			$status = (string) $values['status'];
			$lastError = $values['last_error'];

			if (!is_string($lastError) || trim($lastError) === '') {
				$lastError = sprintf('The Outbox record exhausted its maximum of %d publication attempts.', $this->maxAttempts);
			}

			$lastError = $this->errorSanitizer->sanitize($lastError);

			$query = $this->connection->table('outbox')
				->where('id', (string) $values['id'])
				->where('status', $status)
				->where('attempts', '>=', $this->maxAttempts);

			if ($status === OutboxStatus::CLAIMED->value && $values['claim_token'] !== null) {
				$query->where('claim_token', (string) $values['claim_token']);
			}

			$updated = $query->update([
				'status' => OutboxStatus::FAILED->value,
				'published_at' => null,
				'next_attempt_at' => null,
				'failed_at' => $nowValue,
				'locked_at' => null,
				'claim_token' => null,
				'publisher_id' => null,
				'last_error' => $lastError,
			]);

			if ($updated !== 1) {
				throw new RuntimeException(sprintf('The exhausted Outbox record <%s> could not be failed.', $values['id']));
			}
		}
	}

	private function claimToken(): string
	{
		return \Manager\Shared\Domain\ValueObject\SimpleUuid::random()->value();
	}

	/** @return list<OutboxFailure> */
	private function recentFailures(int $limit): array
	{
		$rows = $this->connection->select(
			sprintf(
				'SELECT event_id, event_name, producer, aggregate_type, aggregate_id, attempts, status, failed_at, next_attempt_at, last_error
				FROM outbox
				WHERE status IN (?, ?)
					AND last_error IS NOT NULL
					AND last_error <> ?
				ORDER BY COALESCE(failed_at, created_at) DESC, id DESC
				LIMIT %d',
				$limit
			),
			[OutboxStatus::FAILED->value, OutboxStatus::PENDING->value, '']
		);

		$failures = [];

		foreach ($rows as $row) {
			$values = (array) $row;
			$failures[] = new OutboxFailure(
				(string) $values['event_id'],
				(string) $values['event_name'],
				(string) $values['producer'],
				(string) $values['aggregate_type'],
				(string) $values['aggregate_id'],
				(int) $values['attempts'],
				OutboxStatus::from((string) $values['status']),
				$this->optionalDate($values['failed_at'] ?? null),
				$this->optionalDate($values['next_attempt_at'] ?? null),
				$this->errorSanitizer->sanitize((string) $values['last_error']),
			);
		}

		return $failures;
	}

	private function optionalDate(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || $value === '') {
			return null;
		}

		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}

	private function formatDate(DateTimeImmutable $date): string
	{
		return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
	}
}
