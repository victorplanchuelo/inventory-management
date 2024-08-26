<?php

declare(strict_types=1);

namespace Manager\Domain\User\ValueObjects;

use InvalidArgumentException;
use Manager\Shared\Domain\ValueObject\StringValueObject;

final class UserEmail extends StringValueObject
{
	public function __construct(string $value)
	{
		if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException(sprintf('<%s> does not allow the value <%s>.', self::class, $value));
		}

		parent::__construct($value);
	}
}
