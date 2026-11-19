<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use InvalidArgumentException;

final class InvalidInboxRetention extends InvalidArgumentException
{
	public static function becauseItIsNotAPositiveInteger(mixed $value): self
	{
		return new self(sprintf(
			'The Inbox retention must be a positive integer number of days, <%s> given.',
			is_scalar($value) ? (string) $value : get_debug_type($value)
		));
	}
}
