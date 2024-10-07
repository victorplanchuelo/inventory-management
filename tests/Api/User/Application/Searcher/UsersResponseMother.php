<?php

declare(strict_types=1);

namespace Tests\Api\User\Application\Searcher;

use Manager\Api\User\Application\UserResponse;
use Manager\Api\User\Application\UsersResponse;

final class UsersResponseMother
{
	public static function create(UserResponse ...$response,): UsersResponse
	{
		return new UsersResponse(...$response);
	}
}
