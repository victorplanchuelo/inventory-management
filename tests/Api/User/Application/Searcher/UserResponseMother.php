<?php

declare(strict_types=1);

namespace Tests\Api\User\Application\Searcher;


use Manager\Api\User\Application\UserResponse;

final class UserResponseMother
{
	public static function create(
		int $id,
        string $uuid = null,
		string $name = null,
		string $email = null,
		string $password = null
	): UserResponse {
		return new UserResponse($id, $uuid, $name, $email, $password);
	}
}
