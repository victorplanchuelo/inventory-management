<?php

declare(strict_types=1);

namespace Manager\Api\User\Application;

use Manager\Shared\Domain\Bus\Query\Response;

final readonly class UsersResponse implements Response
{
	private array $users;

	public function __construct(UserResponse ...$users)
	{
		$this->users = $users;
	}

	public function users(): array
	{
		return $this->users;
	}
}
