<?php

declare(strict_types=1);

namespace Manager\Infrastructure\User;

use Manager\Domain\User\User;
use Manager\Domain\User\UserRepository;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserId;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;
use Manager\Infrastructure\User\Persistence\UserEloquentModel;
use function Lambdish\Phunctional\map;

final class MySqlUserRepository implements UserRepository
{
	public function searchAll(): array
	{
		$usersEloquent = UserEloquentModel::all();
        return map($this->toResponse(), $usersEloquent);
	}

	public function create(User $user): bool
	{
		$user = new UserEloquentModel(
			[
				'name' => $user->name()->value(),
				'email' => $user->email()->value(),
				'password' => $user->password()->value(),
			]
		);
		return $user->save();
	}

    private function toResponse(): callable
    {
        return static fn (UserEloquentModel $user): User => new User(
            new UserId($user->id),
            new UserName($user->name),
            new UserEmail($user->email),
            new UserPassword($user->password)
        );
    }

    public function searchByEmail(UserEmail $email): ?User
    {
        $user = UserEloquentModel::where('email', $email->value())->first();
    }
}
