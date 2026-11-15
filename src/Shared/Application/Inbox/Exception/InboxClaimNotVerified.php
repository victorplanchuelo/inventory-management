<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use RuntimeException;

final class InboxClaimNotVerified extends RuntimeException
{
	public static function forEvent(string $consumer, string $eventId): self
	{
		return new self(
			sprintf('The inbox claim for consumer <%s> and event <%s> is not committed and complete.', $consumer, $eventId)
		);
	}
}
