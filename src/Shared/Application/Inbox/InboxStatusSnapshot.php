<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InboxStatusSnapshot
{
	/**
	 * @param array<string, int> $recordsByConsumer
	 */
	public function __construct(
		public string $context,
		public int $totalRecords,
		public int $processedRecords,
		public int $staleRecords,
		public array $recordsByConsumer,
		public ?DateTimeImmutable $oldestProcessedAt,
		public ?DateTimeImmutable $newestProcessedAt,
		public ?int $purgeableRecords,
	) {
		if (trim($this->context) === '') {
			throw new InvalidArgumentException('The Inbox status context must not be empty.');
		}

		foreach ([
			'totalRecords' => $this->totalRecords,
			'processedRecords' => $this->processedRecords,
			'staleRecords' => $this->staleRecords,
			'purgeableRecords' => $this->purgeableRecords,
		] as $name => $count) {
			if ($count !== null && $count < 0) {
				throw new InvalidArgumentException(sprintf('The Inbox status count <%s> cannot be negative.', $name));
			}
		}

		foreach ($this->recordsByConsumer as $consumer => $count) {
			if (trim($consumer) === '') {
				throw new InvalidArgumentException('The Inbox status consumer name must not be empty.');
			}

			if ($count < 0) {
				throw new InvalidArgumentException('The Inbox status consumer count cannot be negative.');
			}
		}
	}
}
