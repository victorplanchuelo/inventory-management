<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Searcher;

use Manager\Api\User\Application\UserResponse;
use Manager\Api\User\Application\UsersResponse;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserRepository;
use function Lambdish\Phunctional\map;

final readonly class AllUsersSearcher
{
	public function __construct(private UserRepository $repository) {}

	public function __invoke(): UsersResponse
	{
		return new UsersResponse(...map($this->toResponse(), $this->repository->searchAll()));
	}

	private function toResponse(): callable
	{
		return static fn (User $user): UserResponse => new UserResponse(
			$user->id()->value(),
			$user->uuid()->value(),
			$user->name()->value(),
			$user->email()->value(),
			$user->password()->value()
		);
	}
}
