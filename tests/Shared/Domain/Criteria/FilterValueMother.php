<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Criteria;

use Manager\Shared\Domain\Criteria\FilterValue;
use Tests\Shared\Domain\WordMother;

final class FilterValueMother
{
	public static function create(?string $value = null): FilterValue
	{
		return new FilterValue($value ?? WordMother::create());
	}
}
