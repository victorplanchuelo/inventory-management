<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Tests\Shared\Domain\EmailMother;

final class UserEmailMother
{
	public static function create(?string $value = null): UserEmail
	{
		return new UserEmail($value ?? EmailMother::create());
	}
}
