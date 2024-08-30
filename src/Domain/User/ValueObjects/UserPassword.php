<?php

declare(strict_types=1);

namespace Manager\Domain\User\ValueObjects;

use Manager\Shared\Domain\ValueObject\StringValueObject;

final class UserPassword extends StringValueObject
{
	public function __construct(string $value)
	{
		parent::__construct($value);
	}
}
