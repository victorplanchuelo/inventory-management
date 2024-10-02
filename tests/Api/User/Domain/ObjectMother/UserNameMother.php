<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Domain\ValueObjects\UserName;
use Tests\Shared\Domain\WordMother;

final class UserNameMother
{
	public static function create(?string $value = null): UserName
	{
		return new UserName($value ?? WordMother::create());
	}
}
