<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxRetention;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;

final class PurgeOutboxCommand extends Command
{
	protected $signature = 'manager:integration-events:outbox:purge
		{--published-older-than-days= : Retention window in days for published records}';

	protected $description = 'Purge published outbox records older than the configured retention window';

	public function __construct(private readonly OutboxRepository $repository)
	{
		parent::__construct();
	}

	public function handle(): int
	{
		try {
			$retention = OutboxRetention::resolve(
				$this->option('published-older-than-days'),
				config('outbox.published_retention_days')
			);
		} catch (InvalidArgumentException $exception) {
			$this->error($exception->getMessage());

			return self::FAILURE;
		}

		$cutoff = $retention->cutoff(new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$purged = $this->repository->purgePublishedBefore($cutoff);

		$this->info(sprintf(
			'Purged %d published outbox record(s) older than %s UTC (retention: %d day(s)).',
			$purged,
			$cutoff->format('Y-m-d H:i:s'),
			$retention->publishedRetentionDays,
		));

		return self::SUCCESS;
	}
}
