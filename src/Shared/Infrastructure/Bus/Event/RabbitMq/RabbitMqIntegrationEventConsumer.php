<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPEnvelope;
use AMQPQueue;
use AMQPQueueException;
use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\IntegrationEvent\Exception\IntegrationEventEnvelopeAware;
use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;
use Manager\Shared\Infrastructure\Inbox\InboxLogger;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
use Throwable;

final readonly class RabbitMqIntegrationEventConsumer
{
	public function __construct(
		private RabbitMqConnection $connection,
		private IntegrationEventEnvelopeDeserializer $deserializer,
		private InboxProcessor $processor,
		private InboxLogger $logger,
		private IntegrationEventFailurePublisher $failurePublisher,
		private string $exchangeName,
		private int $maxRetries,
	) {}

	public function consume(IntegrationEventSubscriber $subscriber, string $queueName): void
	{
		try {
			$this->connection
				->queue($queueName)
				->consume($this->consumer($subscriber, $queueName));
		} catch (AMQPQueueException) {
			// No messages are available in the queue.
		}
	}

	public function consumeOne(IntegrationEventSubscriber $subscriber, string $queueName): bool
	{
		$queue = $this->connection->queue($queueName);
		$envelope = $queue->get();

		if (!$envelope instanceof AMQPEnvelope) {
			return false;
		}

		($this->consumer($subscriber, $queueName))($envelope, $queue);

		return true;
	}

	private function consumer(IntegrationEventSubscriber $subscriber, string $queueName): callable
	{
		return function (AMQPEnvelope $envelope, AMQPQueue $queue) use ($subscriber, $queueName): void {
			try {
				$event = $this->deserializer->deserialize($envelope->getBody(), $subscriber);
			} catch (Throwable $error) {
				if ($error instanceof InvalidIntegrationEventEnvelope) {
					$this->logger->recordMalformed($queueName, $error);
				}

				$event = $error instanceof IntegrationEventEnvelopeAware ? $error->envelope() : null;

				$this->routeFailure($envelope, $queue, $queueName, $subscriber->consumerName(), $error, $event);
				$queue->ack($envelope->getDeliveryTag());

				return;
			}

			try {
				$result = $this->processor->process($event, $subscriber);
			} catch (Throwable $error) {
				$this->routeFailure($envelope, $queue, $queueName, $subscriber->consumerName(), $error, $event);
				$queue->ack($envelope->getDeliveryTag());

				return;
			}

			if ($result === InboxProcessingResult::DUPLICATE) {
				$this->logger->recordDuplicate($event, $subscriber->consumerName());
			} elseif ($result === InboxProcessingResult::PROCESSED) {
				$this->logger->recordProcessed($event, $subscriber->consumerName());
			}

			$queue->ack($envelope->getDeliveryTag());
		};
	}

	private function routeFailure(
		AMQPEnvelope $envelope,
		AMQPQueue $queue,
		string $queueName,
		string $consumerName,
		Throwable $error,
		?IntegrationEventEnvelope $event
	): void {
		$headers = $envelope->getHeaders();
		$redeliveryCount = $this->redeliveryCount($headers);
		$routingKey = $queue->getName();
		$permanent = $error instanceof PermanentIntegrationEventFailure;

		if ($permanent || $redeliveryCount >= $this->maxRetries) {
			$this->publish(
				'dead_letter-' . $this->exchangeName,
				$envelope,
				$routingKey,
				$headers,
				$redeliveryCount,
				$this->deadLetterDiagnostics($consumerName, $queueName, $error, $event)
			);
			$this->logger->recordDeadLetter($event, $queueName, $error);

			return;
		}

		$nextRedeliveryCount = $redeliveryCount + 1;

		$this->publish('retry-' . $this->exchangeName, $envelope, $routingKey, $headers, $nextRedeliveryCount);
		$this->logger->recordRetry($event, $queueName, $nextRedeliveryCount, $error);
	}

	/**
	 * @param array<string, mixed> $headers
	 * @param array<string, string> $diagnostics
	 */
	private function publish(
		string $exchangeName,
		AMQPEnvelope $envelope,
		string $routingKey,
		array $headers,
		int $redeliveryCount,
		array $diagnostics = []
	): void {
		$this->failurePublisher->publish(
			$exchangeName,
			$routingKey,
			$envelope->getBody(),
			[
				'message_id' => $envelope->getMessageId(),
				'content_type' => $envelope->getContentType(),
				'content_encoding' => $envelope->getContentEncoding(),
				'priority' => $envelope->getPriority(),
				'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
				'headers' => array_merge($headers, ['redelivery_count' => $redeliveryCount], $diagnostics),
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function deadLetterDiagnostics(
		string $consumerName,
		string $queueName,
		Throwable $error,
		?IntegrationEventEnvelope $event
	): array {
		$diagnostics = [
			'x-error-class' => $error::class,
			'x-error-message' => (new OutboxErrorSanitizer())->sanitize($error->getMessage()),
			'x-consumer' => $consumerName,
			'x-original-queue' => $queueName,
			'x-failed-at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
		];

		if ($event !== null) {
			$diagnostics['x-event-id'] = $event->eventId;
			$diagnostics['x-event-name'] = $event->eventName;
		}

		return $diagnostics;
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private function redeliveryCount(array $headers): int
	{
		$value = $headers['redelivery_count'] ?? 0;

		return is_numeric($value) ? (int) $value : 0;
	}
}
