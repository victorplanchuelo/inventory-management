<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Criteria;

use Manager\Shared\Domain\Criteria\OrderBy;
use Tests\Shared\Domain\WordMother;

final class OrderByMother
{
	public static function create(?string $fieldName = null): OrderBy
	{
		return new OrderBy($fieldName ?? WordMother::create());
	}
}
