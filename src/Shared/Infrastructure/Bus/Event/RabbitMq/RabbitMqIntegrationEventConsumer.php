<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPEnvelope;
use AMQPQueue;
use AMQPQueueException;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;
use Manager\Shared\Infrastructure\Inbox\InboxLogger;
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
				$this->logger->recordMalformed($queueName, $error);
				$this->routeFailure($envelope, $queue, $queueName, $error, null);
				$queue->ack($envelope->getDeliveryTag());

				return;
			}

			try {
				$result = $this->processor->process($event, $subscriber);
			} catch (Throwable $error) {
				$this->routeFailure($envelope, $queue, $queueName, $error, $event);
				$queue->ack($envelope->getDeliveryTag());

				return;
			}

			if ($result === InboxProcessingResult::DUPLICATE) {
				$this->logger->recordDuplicate($event, $subscriber->consumerName());
			} else {
				$this->logger->recordProcessed($event, $subscriber->consumerName());
			}

			$queue->ack($envelope->getDeliveryTag());
		};
	}

	private function routeFailure(
		AMQPEnvelope $envelope,
		AMQPQueue $queue,
		string $queueName,
		Throwable $error,
		?IntegrationEventEnvelope $event
	): void {
		$headers = $envelope->getHeaders();
		$redeliveryCount = $this->redeliveryCount($headers);
		$routingKey = $queue->getName();

		if ($redeliveryCount >= $this->maxRetries) {
			$this->publish('dead_letter-' . $this->exchangeName, $envelope, $routingKey, $headers, $redeliveryCount);
			$this->logger->recordDeadLetter($event, $queueName, $error);

			return;
		}

		$nextRedeliveryCount = $redeliveryCount + 1;

		$this->publish('retry-' . $this->exchangeName, $envelope, $routingKey, $headers, $nextRedeliveryCount);
		$this->logger->recordRetry($event, $queueName, $nextRedeliveryCount, $error);
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private function publish(
		string $exchangeName,
		AMQPEnvelope $envelope,
		string $routingKey,
		array $headers,
		int $redeliveryCount
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
				'headers' => array_merge($headers, ['redelivery_count' => $redeliveryCount]),
			]
		);
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
