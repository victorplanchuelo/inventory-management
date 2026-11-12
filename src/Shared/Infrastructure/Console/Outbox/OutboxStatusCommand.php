<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Manager\Shared\Application\Outbox\OutboxFailure;
use Manager\Shared\Application\Outbox\OutboxStatusSnapshot;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;

final class OutboxStatusCommand extends Command
{
	protected $signature = 'manager:integration-events:outbox:status';

	protected $description = 'Report the operational status of the transactional outbox';

	public function __construct(
		private readonly OutboxRepository $repository,
		private readonly OutboxErrorSanitizer $errorSanitizer,
	) {
		parent::__construct();
	}

	public function handle(): int
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$status = $this->repository->status($now);

		$this->info('Outbox status');
		$this->line(sprintf('Database connection: %s', (string) config('database.default', 'default')));
		$this->line(sprintf('Publisher exchange: %s', (string) config('outbox.exchange', 'integration_events')));
		$this->line(sprintf('Publisher lease: %s', $this->configurationValue('outbox.lease_seconds', 300)));
		$this->line(sprintf('Publish batch: %s', $this->configurationValue('outbox.publish_batch', 100)));
		$this->line(sprintf('Confirm timeout: %s seconds', $this->configurationValue('outbox.confirm_timeout_seconds', 5.0)));
		$this->newLine();

		$this->line('Records');
		$this->line(sprintf('Pending: %d', $status->pending));
		$this->line(sprintf('Claimed: %d', $status->claimed));
		$this->line(sprintf('Published: %d', $status->published));
		$this->line(sprintf('Failed: %d', $status->failed));
		$this->line(sprintf('Retrying pending: %d', $status->retrying));
		$this->line(sprintf('Oldest pending age: %s', $this->formatAge($status->oldestPendingAgeSeconds($now))));
		$this->line(sprintf('Oldest pending created at: %s', $this->formatDate($status->oldestPendingCreatedAt)));
		$this->line(sprintf('Stale claims: %d', $status->staleClaims));
		$this->line(sprintf('Active publishers: %d', $status->activePublishers));
		$this->line(sprintf('Last published at: %s', $this->formatDate($status->lastPublishedAt)));
		$this->newLine();

		$this->line('Recent publication errors');
		$this->renderFailures($status);

		return self::SUCCESS;
	}

	private function renderFailures(OutboxStatusSnapshot $status): void
	{
		if ($status->recentFailures === []) {
			$this->line('None');

			return;
		}

		foreach ($status->recentFailures as $failure) {
			$this->line($this->formatFailure($failure));
		}
	}

	private function formatFailure(OutboxFailure $failure): string
	{
		return sprintf(
			'[%s] event %s | producer %s | %s %s | attempts %d | failed at %s | next retry %s | error: %s',
			$failure->status->value,
			$failure->eventId,
			$failure->producer,
			$failure->aggregateType,
			$failure->aggregateId,
			$failure->attempts,
			$this->formatDate($failure->failedAt),
			$this->formatDate($failure->nextAttemptAt),
			$this->truncate($this->errorSanitizer->sanitize($failure->lastError)),
		);
	}

	private function configurationValue(string $key, float | int $default): string
	{
		$value = config($key, $default);

		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			return (string) $default;
		}

		return (string) $value;
	}

	private function formatAge(?int $seconds): string
	{
		if ($seconds === null) {
			return 'none';
		}

		if ($seconds < 60) {
			return sprintf('%ds', $seconds);
		}

		$minutes = intdiv($seconds, 60);

		if ($minutes < 60) {
			return sprintf('%dm %ds', $minutes, $seconds % 60);
		}

		$hours = intdiv($minutes, 60);
		$minutes %= 60;

		if ($hours < 24) {
			return sprintf('%dh %dm', $hours, $minutes);
		}

		$days = intdiv($hours, 24);

		return sprintf('%dd %dh', $days, $hours % 24);
	}

	private function formatDate(?DateTimeImmutable $date): string
	{
		if ($date === null) {
			return 'never';
		}

		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
	}

	private function truncate(string $value): string
	{
		if (mb_strlen($value) <= 240) {
			return $value;
		}

		return mb_substr($value, 0, 237) . '...';
	}
}
