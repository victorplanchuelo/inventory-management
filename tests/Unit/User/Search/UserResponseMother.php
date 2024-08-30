<?php

declare(strict_types=1);

namespace Tests\Unit\User\Search;

use Manager\Application\User\UserResponse;

final class UserResponseMother
{
	public static function create(
		int $id,
		string $name = null,
		string $email = null,
		string $password = null
	): UserResponse {
		return new UserResponse($id, $name, $email, $password);
	}
}
