<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use RuntimeException;

final class IncompatibleInboxExchange extends RuntimeException
{
	public static function becauseExchangesDiffer(string $inboxExchange, string $outboxExchange): self
	{
		return new self(
			sprintf('The inbox exchange <%s> must match the outbox exchange <%s>.', $inboxExchange, $outboxExchange)
		);
	}

	public static function becauseExchangeIsEmpty(string $configurationKey): self
	{
		return new self(sprintf('The <%s> exchange name must not be empty.', $configurationKey));
	}
}
