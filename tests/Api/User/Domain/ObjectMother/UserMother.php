<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use Manager\Api\User\Application\Creator\CreateUserCommand;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserId;
use Manager\Api\User\Domain\ValueObjects\UserName;
use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Manager\Api\User\Domain\ValueObjects\UserUuid;

final class UserMother
{
	public static function create(
		?UserId $id = null,
		?UserUuid $uuid = null,
		?UserName $name = null,
		?UserEmail $email = null,
		?UserPassword $password = null
	): User {
		return new User(
			$id ?? UserIdMother::create(),
			$uuid ?? UserUuidMother::create(),
			$name ?? UserNameMother::create(),
			$email ?? UserEmailMother::create(),
			$password ?? UserPasswordMother::create()
		);
	}

	public static function fromRequest(CreateUserCommand $request): User
	{
		return self::create(
			UserIdMother::create(0),
			UserUuidMother::create($request->uuid()),
			UserNameMother::create($request->name()),
			UserEmailMother::create($request->email()),
			UserPasswordMother::create($request->password())
		);
	}
}
