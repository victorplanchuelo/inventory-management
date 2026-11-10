<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

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

		$processed = 0;

		while ($processed < $limit) {
			$records = $this->repository->claim(self::CLAIM_BATCH_SIZE, $this->now(), $this->publisherId);

			if ($records === []) {
				break;
			}

			foreach ($records as $record) {
				$claimToken = $record->claimToken;

				if ($claimToken === null) {
					throw new RuntimeException(sprintf('The claimed Outbox record <%s> has no claim token.', $record->id));
				}

				$this->publisher->publish($record, $this->serializer->serialize($record));

				if (!$this->repository->markPublished($record->id, $claimToken, $this->now())) {
					throw new OutboxClaimLostException(
						sprintf('The Outbox claim for record <%s> was lost before it could be marked as published.', $record->id)
					);
				}

				$processed++;
			}
		}

		return $processed;
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
