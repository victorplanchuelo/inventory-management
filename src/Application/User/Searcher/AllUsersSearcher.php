<?php

declare(strict_types=1);

namespace Manager\Application\User\Searcher;

use Manager\Application\User\UserResponse;
use Manager\Application\User\UsersResponse;
use Manager\Domain\User\User;
use Manager\Domain\User\UserRepository;
use function Lambdish\Phunctional\map;

final readonly class AllUsersSearcher
{
	public function __construct(private UserRepository $repository) {}

	public function __invoke(): UsersResponse
	{
		return new UsersResponse(
            ...map(
                $this->toResponse(),
                $this->repository->searchAll()
            )
        );
	}

	private function toResponse(): callable
	{
		return static fn (User $user): UserResponse => new UserResponse(
			$user->id()->value(),
			$user->name()->value(),
			$user->email()->value(),
			$user->password()->value()
		);
	}
}
