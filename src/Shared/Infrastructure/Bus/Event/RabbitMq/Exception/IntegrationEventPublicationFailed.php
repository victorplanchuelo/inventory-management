<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception;

use RuntimeException;

final class IntegrationEventPublicationFailed extends RuntimeException
{
	public static function becauseTheBrokerDidNotConfirm(): self
	{
		return new self('RabbitMQ did not confirm the integration-event publication.');
	}

	public static function becauseTheBrokerRejectedIt(): self
	{
		return new self('RabbitMQ negatively acknowledged the integration-event publication.');
	}

	public static function becauseItWasUnroutable(
		int $replyCode,
		string $replyText,
		string $exchange,
		string $routingKey
	): self {
		return new self(
			sprintf(
				'RabbitMQ returned the integration event as unroutable: %d %s (exchange <%s>, routing key <%s>).',
				$replyCode,
				$replyText,
				$exchange,
				$routingKey
			)
		);
	}

	public static function becauseTheBrokerFailed(): self
	{
		return new self('RabbitMQ could not confirm the integration-event publication.');
	}
}
