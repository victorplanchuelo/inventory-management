<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Creator;

use Manager\Api\User\Domain\Exceptions\CreateUserException;
use Manager\Api\User\Domain\Exceptions\UserAlreadyExistsException;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserRepository;
use Manager\Api\User\Domain\ValueObjects\UserEmail;

final readonly class UserCreator
{
	public function __construct(private UserRepository $repository) {}

	public function __invoke(string $uuid, string $name, string $email, string $password): void
	{
		//TODO. Change with Criteria pattern
        $user = $this->repository->searchByEmail(new UserEmail($email));

		if ($user !== null) {
			throw new UserAlreadyExistsException($user->id());
		}

		$user = User::create(uuid: $uuid, id: null, name: $name, email: $email, password: $password);
		$userSaved = $this->repository->save($user);
	}
}
