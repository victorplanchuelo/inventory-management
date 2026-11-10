<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPBasicProperties;
use AMQPChannel;
use AMQPConnection;
use AMQPException;
use AMQPExchange;
use InvalidArgumentException;
use Manager\Shared\Infrastructure\Outbox\OutboxEventPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationState;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use Throwable;

final class RabbitMqConfirmedPublisher implements OutboxEventPublisher
{
	private ?AMQPConnection $connection = null;
	private ?AMQPChannel $channel = null;
	private ?AMQPExchange $exchange = null;
	private OutboxPublicationState $state;

	/** @param array<string, mixed> $configuration */
	public function __construct(
		private readonly array $configuration,
		private readonly string $exchangeName,
		private readonly float $confirmTimeoutSeconds,
	) {
		if ($this->exchangeName === '') {
			throw new InvalidArgumentException('The RabbitMQ exchange name must not be empty.');
		}

		if ($this->confirmTimeoutSeconds <= 0) {
			throw new InvalidArgumentException('The RabbitMQ confirmation timeout must be greater than zero.');
		}

		$this->state = new OutboxPublicationState();
	}

	public function publish(OutboxRecord $record, string $envelope): void
	{
		$this->state->reset();

		try {
			$channel = $this->channel();
			$exchange = $this->exchange;

			if ($exchange === null) {
				throw new OutboxPublicationException('The RabbitMQ exchange is not available.');
			}

			$exchange->publish(
				$envelope,
				$record->eventName,
				AMQP_MANDATORY,
				[
					'message_id' => $record->eventId,
					'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
					'content_type' => 'application/json',
					'content_encoding' => 'utf-8',
				]
			);

			$channel->waitForConfirm($this->confirmTimeoutSeconds);
			$this->state->assertSuccessful();
		} catch (OutboxPublicationException $error) {
			$this->resetConnection();

			throw $error;
		} catch (AMQPException $error) {
			$this->resetConnection();

			throw new OutboxPublicationException(
				sprintf('RabbitMQ publication failed: %s', $error->getMessage()),
				0,
				$error
			);
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
		$channel->setConfirmCallback($this->ackCallback(), $this->nackCallback());
		$channel->setReturnCallback($this->returnCallback());

		$exchange = new AMQPExchange($channel);
		$exchange->setName($this->exchangeName);
		$exchange->setType(AMQP_EX_TYPE_TOPIC);
		$exchange->setFlags(AMQP_DURABLE);
		$exchange->declareExchange();

		$this->connection = $connection;
		$this->channel = $channel;
		$this->exchange = $exchange;

		return $channel;
	}

	private function ackCallback(): callable
	{
		return fn (int $deliveryTag, bool $multiple): bool => $this->state->acknowledge($deliveryTag, $multiple);
	}

	private function nackCallback(): callable
	{
		return fn (int $deliveryTag, bool $multiple, bool $requeue): bool => $this->state->reject(
			$deliveryTag,
			$multiple,
			$requeue
		);
	}

	private function returnCallback(): callable
	{
		return function (
			int $replyCode,
			string $replyText,
			string $exchange,
			string $routingKey,
			AMQPBasicProperties $properties,
			string $body,
		): bool {
			return $this->state->recordReturn($replyCode, $replyText, $exchange, $routingKey);
		};
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
		$this->exchange = null;
	}
}
