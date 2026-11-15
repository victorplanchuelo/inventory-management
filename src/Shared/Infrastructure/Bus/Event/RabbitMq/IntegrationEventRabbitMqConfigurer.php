<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPQueue;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;

final readonly class IntegrationEventRabbitMqConfigurer
{
	private IntegrationEventQueueNameFormatter $formatter;

	public function __construct(
		private RabbitMqConnection $connection,
		private string $exchangeName,
	) {
		$this->formatter = new IntegrationEventQueueNameFormatter();
	}

	public function configure(IntegrationEventSubscriber ...$subscribers): void
	{
		$retryExchangeName = 'retry-' . $this->exchangeName;
		$deadLetterExchangeName = 'dead_letter-' . $this->exchangeName;

		$this->declareExchange($this->exchangeName);
		$this->declareExchange($retryExchangeName);
		$this->declareExchange($deadLetterExchangeName);

		foreach ($subscribers as $subscriber) {
			$this->configureSubscriber($subscriber, $retryExchangeName, $deadLetterExchangeName);
		}
	}

	private function configureSubscriber(
		IntegrationEventSubscriber $subscriber,
		string $retryExchangeName,
		string $deadLetterExchangeName
	): void {
		$queueName = $this->formatter->format($subscriber);
		$retryQueueName = $this->formatter->formatRetry($subscriber);
		$deadLetterQueueName = $this->formatter->formatDeadLetter($subscriber);

		$queue = $this->declareQueue($queueName);
		$retryQueue = $this->declareQueue($retryQueueName, $this->exchangeName, $queueName, 1000);
		$deadLetterQueue = $this->declareQueue($deadLetterQueueName);

		$queue->bind($this->exchangeName, $queueName);
		$retryQueue->bind($retryExchangeName, $queueName);
		$deadLetterQueue->bind($deadLetterExchangeName, $queueName);

		foreach (array_keys($subscriber->subscribedTo()) as $eventName) {
			$queue->bind($this->exchangeName, $eventName);
		}
	}

	private function declareExchange(string $exchangeName): void
	{
		$exchange = $this->connection->exchange($exchangeName);
		$exchange->setType(AMQP_EX_TYPE_TOPIC);
		$exchange->setFlags(AMQP_DURABLE);
		$exchange->declareExchange();
	}

	private function declareQueue(
		string $queueName,
		?string $deadLetterExchange = null,
		?string $deadLetterRoutingKey = null,
		?int $messageTtl = null
	): AMQPQueue {
		$queue = $this->connection->queue($queueName);

		if ($deadLetterExchange !== null) {
			$queue->setArgument('x-dead-letter-exchange', $deadLetterExchange);
		}

		if ($deadLetterRoutingKey !== null) {
			$queue->setArgument('x-dead-letter-routing-key', $deadLetterRoutingKey);
		}

		if ($messageTtl !== null) {
			$queue->setArgument('x-message-ttl', $messageTtl);
		}

		$queue->setFlags(AMQP_DURABLE);
		$queue->declareQueue();

		return $queue;
	}
}
