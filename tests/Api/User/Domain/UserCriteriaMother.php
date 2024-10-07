<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain;

use Manager\Shared\Domain\Criteria\Criteria;
use Tests\Shared\Domain\Criteria\CriteriaMother;
use Tests\Shared\Domain\Criteria\FilterMother;
use Tests\Shared\Domain\Criteria\FiltersMother;

final class UserCriteriaMother
{
	public static function emailEqualsTo(string $email): Criteria
	{
		return CriteriaMother::create(
			FiltersMother::createOne(FilterMother::fromValues([
					'field' => 'email',
					'operator' => '=',
					'value' => $email,
				]))
		);
	}

    public static function emailContains(string $email): Criteria
    {
        return CriteriaMother::create(
            FiltersMother::createOne(FilterMother::fromValues([
                'field' => 'email',
                'operator' => 'CONTAINS',
                'value' => $email,
            ]))
        );
    }
}
