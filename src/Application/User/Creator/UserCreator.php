<?php

declare(strict_types=1);

namespace Manager\Application\User\Creator;

use Manager\Domain\User\Exceptions\CreateUserException;
use Manager\Domain\User\Exceptions\UserAlreadyExistsException;
use Manager\Domain\User\User;
use Manager\Domain\User\UserRepository;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;

final readonly class UserCreator
{
	public function __construct(private UserRepository $repository) {}

	public function __invoke(UserName $name, UserEmail $email, UserPassword $password): void
	{
        $user = $this->repository->searchByEmail($email);

		if ($user !== null) {
			throw new UserAlreadyExistsException($user->id());
		}

		$user = User::create($name, $email, $password);
		$wasCreated = $this->repository->create($user);
		if (!$wasCreated) {
			throw new CreateUserException($user->email());
		}
	}
}
