<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\Outbox;

use Illuminate\Console\Command;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;

final class RequeueOutboxCommand extends Command
{
	protected $signature = 'manager:integration-events:outbox:requeue {eventId : Event identifier of the terminally failed outbox record}';

	protected $description = 'Requeue a terminally failed integration event for publication';

	public function __construct(private readonly OutboxRepository $repository)
	{
		parent::__construct();
	}

	public function handle(): int
	{
		$eventId = trim((string) $this->argument('eventId'));

		if ($eventId === '' || !$this->repository->requeueFailed($eventId)) {
			$this->error(sprintf('No terminally failed outbox record was found for event ID <%s>.', $eventId));

			return self::FAILURE;
		}

		$this->info(sprintf('Requeued outbox record for event ID <%s>.', $eventId));

		return self::SUCCESS;
	}
}
