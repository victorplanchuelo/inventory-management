<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception\IntegrationEventPublicationFailed;

final class IntegrationEventPublicationState
{
	private bool $acknowledged = false;
	private bool $negativeAcknowledgement = false;
	private ?IntegrationEventPublicationFailed $returnedFailure = null;

	public function reset(): void
	{
		$this->acknowledged = false;
		$this->negativeAcknowledgement = false;
		$this->returnedFailure = null;
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
		$this->returnedFailure = IntegrationEventPublicationFailed::becauseItWasUnroutable(
			$replyCode,
			$replyText,
			$exchange,
			$routingKey
		);

		return true;
	}

	public function assertSuccessful(): void
	{
		if ($this->returnedFailure !== null) {
			throw $this->returnedFailure;
		}

		if ($this->negativeAcknowledgement) {
			throw IntegrationEventPublicationFailed::becauseTheBrokerRejectedIt();
		}

		if (!$this->acknowledged) {
			throw IntegrationEventPublicationFailed::becauseTheBrokerDidNotConfirm();
		}
	}
}
