<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\Outbox;

use Illuminate\Console\Command;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisher;

final class PublishOutboxCommand extends Command
{
	protected $signature = 'manager:integration-events:outbox:publish {--limit= : Maximum number of records to process}';

	protected $description = 'Publish a bounded batch of pending integration events from the transactional outbox';

	public function __construct(private readonly OutboxPublisher $publisher)
	{
		parent::__construct();
	}

	public function handle(): int
	{
		$limit = $this->option('limit');
		$limit = $limit === null ? (int) config('outbox.publish_batch', 100) : (int) $limit;

		if ($limit < 1) {
			$this->error('The --limit option must be a positive integer.');

			return self::FAILURE;
		}

		$processed = $this->publisher->publishBatch($limit);

		$this->info(sprintf('Processed %d outbox record(s).', $processed));

		return self::SUCCESS;
	}
}
