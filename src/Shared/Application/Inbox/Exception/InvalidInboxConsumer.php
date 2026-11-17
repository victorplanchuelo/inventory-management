<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;

final class InvalidInboxConsumer extends PermanentIntegrationEventFailure
{
	public static function becauseConsumerNameIsInvalid(string $consumerName): self
	{
		return new self(sprintf('The inbox consumer name <%s> is invalid.', $consumerName));
	}

	public static function becauseContextIsNotConfigured(string $consumerName): self
	{
		return new self(sprintf('The inbox consumer <%s> does not belong to a configured inbox context.', $consumerName));
	}
}
