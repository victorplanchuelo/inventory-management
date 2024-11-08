<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Criteria;

use Manager\Shared\Domain\Criteria\Filter;
use Manager\Shared\Domain\Criteria\FilterField;
use Manager\Shared\Domain\Criteria\FilterOperator;
use Manager\Shared\Domain\Criteria\FilterValue;
use Tests\Shared\Domain\RandomElementPicker;

final class FilterMother
{
	public static function create(
		?FilterField $field = null,
		?FilterOperator $operator = null,
		?FilterValue $value = null
	): Filter {
		return new Filter(
			$field ?? FilterFieldMother::create(),
			$operator ?? self::randomOperator(),
			$value ?? FilterValueMother::create()
		);
	}

	/** @param string[] $values */
	public static function fromValues(array $values): Filter
	{
		return Filter::fromPrimitives($values);
	}


	private static function randomOperator(): FilterOperator
	{
		return RandomElementPicker::from(
			FilterOperator::EQUAL,
			FilterOperator::NOT_EQUAL,
			FilterOperator::GT,
			FilterOperator::LT,
			FilterOperator::CONTAINS,
			FilterOperator::NOT_CONTAINS
		);
	}
}
