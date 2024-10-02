<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Domain\ValueObjects\UserId;
use Tests\Shared\Domain\IntegerMother;

final class UserIdMother
{
	public static function create(?int $value = null): UserId
	{
		return new UserId($value ?? IntegerMother::create());
	}
}
