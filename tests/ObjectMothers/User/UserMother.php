<?php

declare(strict_types=1);

namespace Tests\ObjectMothers\User;

use Manager\Application\User\Creator\CreateUserCommand;
use Manager\Domain\User\User;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserId;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;

final class UserMother
{
	public static function create(
		?UserId $id = null,
		?UserName $name = null,
		?UserEmail $email = null,
		?UserPassword $password = null
	): User {
		return new User(
			$id ?? UserIdMother::create(),
			$name ?? UserNameMother::create(),
			$email ?? UserEmailMother::create(),
			$password ?? UserPasswordMother::create()
		);
	}

	public static function fromRequest(CreateUserCommand $request): User
	{
		return self::create(
			UserIdMother::create(0),
			UserNameMother::create($request->name()),
			UserEmailMother::create($request->email()),
			UserPasswordMother::create($request->password())
		);
	}
}
