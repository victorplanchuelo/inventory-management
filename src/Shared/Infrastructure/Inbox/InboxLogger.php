<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class InboxLogger
{
	public function __construct(
		private LoggerInterface $logger,
		private bool $enabled = true,
	) {}

	public function recordProcessed(IntegrationEventEnvelope $event, string $consumer): void
	{
		$this->logEvent('info', 'inbox.event.processed', $event, $consumer, ['outcome' => 'processed']);
	}

	public function recordDuplicate(IntegrationEventEnvelope $event, string $consumer): void
	{
		$this->logEvent('info', 'inbox.event.duplicate', $event, $consumer, ['outcome' => 'duplicate']);
	}

	public function recordMalformed(string $queueName, Throwable $error): void
	{
		if (!$this->enabled) {
			return;
		}

		$this->logger->warning('inbox.event.malformed', [
			'queue' => $queueName,
			'outcome' => 'malformed',
			'error_class' => $error::class,
		]);
	}

	public function recordRetry(
		?IntegrationEventEnvelope $event,
		string $queueName,
		int $redeliveryCount,
		Throwable $error
	): void {
		if (!$this->enabled) {
			return;
		}

		$this->logger->warning(
			'inbox.event.retry',
			$this->failureContext($event, $queueName, $error, [
				'outcome' => 'retry',
				'redelivery_count' => $redeliveryCount,
			])
		);
	}

	public function recordDeadLetter(?IntegrationEventEnvelope $event, string $queueName, Throwable $error): void
	{
		if (!$this->enabled) {
			return;
		}

		$this->logger->error(
			'inbox.event.dead_letter',
			$this->failureContext($event, $queueName, $error, ['outcome' => 'dead_letter'])
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function logEvent(
		string $level,
		string $message,
		IntegrationEventEnvelope $event,
		string $consumer,
		array $context
	): void {
		if (!$this->enabled) {
			return;
		}

		$this->logger->log($level, $message, array_merge([
			'event_id' => $event->eventId,
			'event_name' => $event->eventName,
			'event_version' => $event->eventVersion,
			'aggregate_type' => $event->aggregateType,
			'aggregate_id' => $event->aggregateId,
			'aggregate_version' => $event->aggregateVersion,
			'consumer' => $consumer,
			'correlation_id' => $event->correlationId,
			'causation_id' => $event->causationId,
		], $context));
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private function failureContext(
		?IntegrationEventEnvelope $event,
		string $queueName,
		Throwable $error,
		array $context
	): array {
		$base = array_merge([
			'queue' => $queueName,
			'error_class' => $error::class,
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
}
