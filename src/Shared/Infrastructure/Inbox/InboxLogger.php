<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Infrastructure\Logging\ErrorSanitizer;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class InboxLogger
{
	public function __construct(
		private LoggerInterface $logger,
		private ErrorSanitizer $errorSanitizer,
		private bool $enabled = true,
	) {}

	public function recordProcessed(
		IntegrationEventEnvelope $event,
		string $consumer,
		?float $latencySeconds = null
	): void {
		$this->logEvent('info', 'inbox.event.processed', $event, $consumer, 'processed', $latencySeconds);
	}

	public function recordDuplicate(
		IntegrationEventEnvelope $event,
		string $consumer,
		?float $latencySeconds = null
	): void {
		$this->logEvent('info', 'inbox.event.duplicate', $event, $consumer, 'duplicate', $latencySeconds);
	}

	public function recordStale(
		IntegrationEventEnvelope $event,
		string $consumer,
		?float $latencySeconds = null
	): void {
		$this->logEvent('warning', 'inbox.event.stale', $event, $consumer, 'stale', $latencySeconds);
	}

	public function recordMalformed(string $queueName, Throwable $error, ?string $consumer = null): void
	{
		if (!$this->enabled) {
			return;
		}

		$this->logger->warning('inbox.event.malformed', array_merge([
			'queue' => $queueName,
			'outcome' => 'malformed',
			'error_class' => $error::class,
			'error_message' => $this->errorSanitizer->sanitize($error->getMessage()),
		], $consumer === null ? [] : ['consumer' => $consumer]));
	}

	public function recordRetry(
		?IntegrationEventEnvelope $event,
		string $consumer,
		string $queueName,
		int $redeliveryCount,
		Throwable $error
	): void {
		if (!$this->enabled) {
			return;
		}

		$this->logger->warning(
			'inbox.event.retry',
			$this->failureContext($event, $consumer, $queueName, $error, [
				'outcome' => 'retry',
				'redelivery_count' => $redeliveryCount,
			])
		);
	}

	public function recordDeadLetter(
		?IntegrationEventEnvelope $event,
		string $consumer,
		string $queueName,
		Throwable $error
	): void {
		if (!$this->enabled) {
			return;
		}

		$this->logger->error(
			'inbox.event.dead_letter',
			$this->failureContext($event, $consumer, $queueName, $error, ['outcome' => 'dead_letter'])
		);
	}

	private function logEvent(
		string $level,
		string $message,
		IntegrationEventEnvelope $event,
		string $consumer,
		string $outcome,
		?float $latencySeconds
	): void {
		if (!$this->enabled) {
			return;
		}

		$context = array_merge([
			'event_id' => $event->eventId,
			'event_name' => $event->eventName,
			'event_version' => $event->eventVersion,
			'aggregate_type' => $event->aggregateType,
			'aggregate_id' => $event->aggregateId,
			'aggregate_version' => $event->aggregateVersion,
			'consumer' => $consumer,
			'correlation_id' => $event->correlationId,
			'causation_id' => $event->causationId,
			'outcome' => $outcome,
		], $this->latencyContext($latencySeconds));

		$this->logger->log($level, $message, $context);
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private function failureContext(
		?IntegrationEventEnvelope $event,
		string $consumer,
		string $queueName,
		Throwable $error,
		array $context
	): array {
		$base = array_merge([
			'consumer' => $consumer,
			'queue' => $queueName,
			'error_class' => $error::class,
			'error_message' => $this->errorSanitizer->sanitize($error->getMessage()),
		], $context);

		if ($event === null) {
			return $base;
		}

		return array_merge([
			'event_id' => $event->eventId,
			'event_name' => $event->eventName,
			'event_version' => $event->eventVersion,
			'aggregate_type' => $event->aggregateType,
			'aggregate_id' => $event->aggregateId,
			'aggregate_version' => $event->aggregateVersion,
			'correlation_id' => $event->correlationId,
			'causation_id' => $event->causationId,
		], $base);
	}

	/**
	 * @return array<string, float>
	 */
	private function latencyContext(?float $latencySeconds): array
	{
		if ($latencySeconds === null) {
			return [];
		}

		return ['latency_ms' => round(max(0.0, $latencySeconds) * 1000, 3)];
	}
}
