<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\Exception\IncompatibleInboxExchange;

final readonly class InboxExchangeValidator
{
	public function __construct(
		private string $inboxExchange,
		private string $outboxExchange,
	) {}

	public function assertCompatible(): void
	{
		if ($this->inboxExchange === '') {
			throw IncompatibleInboxExchange::becauseExchangeIsEmpty('inbox');
		}

		if ($this->outboxExchange === '') {
			throw IncompatibleInboxExchange::becauseExchangeIsEmpty('outbox');
		}

		if ($this->inboxExchange !== $this->outboxExchange) {
			throw IncompatibleInboxExchange::becauseExchangesDiffer($this->inboxExchange, $this->outboxExchange);
		}
	}
}
