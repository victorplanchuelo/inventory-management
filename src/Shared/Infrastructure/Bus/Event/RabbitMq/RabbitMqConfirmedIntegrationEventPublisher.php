<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPBasicProperties;
use AMQPChannel;
use AMQPConnection;
use AMQPException;
use AMQPExchange;
use InvalidArgumentException;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception\IntegrationEventPublicationFailed;
use Throwable;

final class RabbitMqConfirmedIntegrationEventPublisher implements IntegrationEventFailurePublisher
{
	private ?AMQPConnection $connection = null;
	private ?AMQPChannel $channel = null;
	/** @var array<string, AMQPExchange> */
	private array $exchanges = [];
	private readonly IntegrationEventPublicationState $state;

	/** @param array<string, mixed> $configuration */
	public function __construct(
		private readonly array $configuration,
		private readonly float $confirmTimeoutSeconds,
	) {
		if ($this->confirmTimeoutSeconds <= 0) {
			throw new InvalidArgumentException('The RabbitMQ confirmation timeout must be greater than zero.');
		}

		$this->state = new IntegrationEventPublicationState();
	}

	public function publish(string $exchangeName, string $routingKey, string $body, array $properties): void
	{
		$this->state->reset();

		try {
			$channel = $this->channel();
			$exchange = $this->exchange($exchangeName);

			$exchange->publish($body, $routingKey, AMQP_MANDATORY, $properties);

			$channel->waitForConfirm($this->confirmTimeoutSeconds);
			$this->state->assertSuccessful();
		} catch (IntegrationEventPublicationFailed $error) {
			$this->resetConnection();

			throw $error;
		} catch (AMQPException) {
			$this->resetConnection();

			throw IntegrationEventPublicationFailed::becauseTheBrokerFailed();
		}
	}

	public function __destruct()
	{
		$this->resetConnection();
	}

	private function channel(): AMQPChannel
	{
		if ($this->channel !== null && $this->channel->isConnected()) {
			return $this->channel;
		}

		$this->resetConnection();

		$connection = new AMQPConnection($this->configuration);
		$connection->connect();

		$channel = new AMQPChannel($connection);
		$channel->confirmSelect();
		$channel->setConfirmCallback(
			fn (int $deliveryTag, bool $multiple): bool => $this->state->acknowledge($deliveryTag, $multiple),
			fn (int $deliveryTag, bool $multiple, bool $requeue): bool => $this->state->reject(
				$deliveryTag,
				$multiple,
				$requeue
			)
		);
		$channel->setReturnCallback(
			function (
				int $replyCode,
				string $replyText,
				string $exchange,
				string $routingKey,
				AMQPBasicProperties $properties,
				string $body,
			): bool {
				return $this->state->recordReturn($replyCode, $replyText, $exchange, $routingKey);
			}
		);

		$this->connection = $connection;
		$this->channel = $channel;

		return $channel;
	}

	private function exchange(string $exchangeName): AMQPExchange
	{
		if (!array_key_exists($exchangeName, $this->exchanges)) {
			$exchange = new AMQPExchange($this->channel());
			$exchange->setName($exchangeName);

			$this->exchanges[$exchangeName] = $exchange;
		}

		return $this->exchanges[$exchangeName];
	}

	private function resetConnection(): void
	{
		try {
			$this->channel?->close();
		} catch (Throwable) {
			// Best effort: the channel may already be closed by the broker.
		}

		try {
			$this->connection?->disconnect();
		} catch (Throwable) {
			// Best effort: the connection may already be closed by the broker.
		}

		$this->channel = null;
		$this->connection = null;
		$this->exchanges = [];
	}
}
