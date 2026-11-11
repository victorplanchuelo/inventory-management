<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxStatus;
use RuntimeException;

final readonly class MySqlOutboxRepository implements OutboxRepository
{
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		private Connection $connection,
		private int $leaseSeconds,
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

	private function formatDate(DateTimeImmutable $date): string
	{
		return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
	}
}
