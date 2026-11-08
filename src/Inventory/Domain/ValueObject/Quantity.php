<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\ValueObject;

use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Shared\Domain\ValueObject\IntValueObject;

final class Quantity extends IntValueObject
{
	public function __construct(int $value)
	{
		if ($value < 0) {
			throw new InvalidQuantityException($value);
		}

		parent::__construct($value);
	}

	public function isPositive(): bool
	{
		return $this->value() > 0;
	}
}
