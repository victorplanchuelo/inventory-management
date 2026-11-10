<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

final class OutboxPublicationState
{
	private bool $acknowledged = false;
	private bool $negativeAcknowledgement = false;
	private ?string $returnedMessage = null;

	public function reset(): void
	{
		$this->acknowledged = false;
		$this->negativeAcknowledgement = false;
		$this->returnedMessage = null;
	}

	public function acknowledge(int $deliveryTag, bool $multiple): bool
	{
		$this->acknowledged = true;

		return false;
	}

	public function reject(int $deliveryTag, bool $multiple, bool $requeue): bool
	{
		$this->negativeAcknowledgement = true;

		return false;
	}

	public function recordReturn(int $replyCode, string $replyText, string $exchange, string $routingKey): bool
	{
		$this->returnedMessage = sprintf(
			'RabbitMQ returned the integration event as unroutable: %d %s (exchange <%s>, routing key <%s>).',
			$replyCode,
			$replyText,
			$exchange,
			$routingKey,
		);

		return true;
	}

	public function assertSuccessful(): void
	{
		if ($this->returnedMessage !== null) {
			throw new OutboxPublicationException($this->returnedMessage);
		}

		if ($this->negativeAcknowledgement) {
			throw new OutboxPublicationException('RabbitMQ negatively acknowledged the integration event.');
		}

		if (!$this->acknowledged) {
			throw new OutboxPublicationException('RabbitMQ did not confirm the integration event.');
		}
	}
}
