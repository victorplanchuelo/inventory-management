<?php

declare(strict_types=1);

namespace Manager\Infrastructure\User;

use Illuminate\Support\Facades\Hash;
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

	public function create(User $user): ?User
    {
		$userModel = new UserEloquentModel(
			[
				'name' => $user->name()->value(),
				'email' => $user->email()->value(),
				'password' => $user->password()->value(),
			]
		);

		$userModel->save();
        return new User(
            new UserId($userModel->id),
            new UserName($userModel->name),
            new UserEmail($userModel->email),
            new UserPassword($userModel->password)
        );
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
        $user = null;
        $userEloquent = UserEloquentModel::where('email', $email->value())->first();
        if($userEloquent !== null) {
            $user = new User(
                new UserId($userEloquent->id),
                new UserName($userEloquent->name),
                new UserEmail($userEloquent->email),
                new UserPassword($userEloquent->password)
            );
        }

        return $user;
    }
}
