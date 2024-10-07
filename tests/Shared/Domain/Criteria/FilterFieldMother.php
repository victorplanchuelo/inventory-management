<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Criteria;

use Manager\Shared\Domain\Criteria\FilterField;
use Tests\Shared\Domain\WordMother;

final class FilterFieldMother
{
	public static function create(?string $fieldName = null): FilterField
	{
		return new FilterField($fieldName ?? WordMother::create());
	}
}
