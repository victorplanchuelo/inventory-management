<?php

declare(strict_types=1);

namespace Manager\Api\User\Infrastructure;

use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserRepository;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserId;
use Manager\Api\User\Domain\ValueObjects\UserName;
use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Manager\Api\User\Domain\ValueObjects\UserUuid;
use Manager\Api\User\Infrastructure\Persistence\UserEloquentModel;
use function Lambdish\Phunctional\map;

final class MySqlUserRepository implements UserRepository
{
	public function searchAll(): array
	{
		$usersEloquent = UserEloquentModel::all();
		return map($this->toResponse(), $usersEloquent);
	}

	public function save(User $user): User
	{
		$userModel = new UserEloquentModel(
			[
                'uuid' => $user->uuid()->value(),
				'name' => $user->name()->value(),
				'email' => $user->email()->value(),
				'password' => $user->password()->value(),
			]
		);

		$userModel->save();

        return new User(
            new UserId($userModel->id),
            new UserUuid($userModel->uuid),
            new UserName($userModel->name),
            new UserEmail($userModel->email),
            new UserPassword($userModel->password)
        );
	}

	private function toResponse(): callable
	{
		return static fn (UserEloquentModel $user): User => new User(
            new UserId($user->id),
            new UserUuid($user->uuid),
			new UserName($user->name),
			new UserEmail($user->email),
			new UserPassword($user->password)
		);
	}

	//TODO. Change with Criteria
    public function searchByEmail(UserEmail $email): ?User
	{
		$user = null;
		$userEloquent = UserEloquentModel::where('email', $email->value())->first();
		if ($userEloquent !== null) {
			$user = new User(
                new UserId($userEloquent->id),
                new UserUuid($userEloquent->uuid),
				new UserName($userEloquent->name),
				new UserEmail($userEloquent->email),
				new UserPassword($userEloquent->password)
			);
		}

		return $user;
	}

    //TODO. Change with Criteria
    public function search(UserUuid $uuid): ?User
    {
        $user = null;
        $userEloquent = UserEloquentModel::where('uuid', $uuid->value())->first();
        if ($userEloquent !== null) {
            $user = new User(
                new UserId($userEloquent->id),
                new UserUuid($userEloquent->uuid),
                new UserName($userEloquent->name),
                new UserEmail($userEloquent->email),
                new UserPassword($userEloquent->password)
            );
        }

        return $user;
    }
}
