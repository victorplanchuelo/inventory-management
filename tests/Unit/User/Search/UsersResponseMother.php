<?php

declare(strict_types=1);

namespace Tests\Unit\User\Search;

use Manager\Application\User\UserResponse;
use Manager\Application\User\UsersResponse;

final class UsersResponseMother
{
	public static function create(
		UserResponse ...$response,
	): UsersResponse {
		return new UsersResponse(...$response);
	}
}
