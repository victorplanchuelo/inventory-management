<?php

declare(strict_types=1);

namespace Tests\ObjectMothers\User;

use Manager\Domain\User\ValueObjects\UserPassword;
use Tests\Shared\Domain\PasswordMother;

final class UserPasswordMother
{
	public static function create(?string $value = null): UserPassword
	{
		return new UserPassword($value ?? PasswordMother::create());
	}
}
