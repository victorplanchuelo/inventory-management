<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Domain\ValueObjects\UserUuid;
use Tests\Shared\Domain\UuidMother;

final class UserUuidMother
{
	public static function create(?string $value = null): UserUuid
	{
		return new UserUuid($value ?? UuidMother::create());
	}
}
