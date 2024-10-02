<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Tests\Shared\Domain\PasswordMother;

final class UserPasswordMother
{
	public static function create(?string $value = null): UserPassword
	{
		return new UserPassword($value ?? PasswordMother::create());
	}
}
