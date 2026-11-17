<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use Manager\Shared\Application\IntegrationEvent\Exception\RetryableIntegrationEventFailure;

final class InboxClaimNotVerified extends RetryableIntegrationEventFailure
{
	public static function forEvent(string $consumer, string $eventId): self
	{
		return new self(
			sprintf('The inbox claim for consumer <%s> and event <%s> is not committed and complete.', $consumer, $eventId)
		);
	}
}
