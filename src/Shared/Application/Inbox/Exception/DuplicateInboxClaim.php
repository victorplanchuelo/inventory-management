<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use RuntimeException;

final class DuplicateInboxClaim extends RuntimeException
{
	public function __construct(string $consumer, string $eventId)
	{
		parent::__construct(
			sprintf('The inbox claim for consumer <%s> and event <%s> already exists.', $consumer, $eventId)
		);
	}
}
