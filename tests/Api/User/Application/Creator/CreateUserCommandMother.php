<?php

declare(strict_types=1);

namespace Tests\Api\User\Application\Creator;

use Manager\Api\User\Application\Creator\CreateUserCommand;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserName;
use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Manager\Api\User\Domain\ValueObjects\UserUuid;
use Tests\Api\User\Domain\ObjectMother\UserEmailMother;
use Tests\Api\User\Domain\ObjectMother\UserNameMother;
use Tests\Api\User\Domain\ObjectMother\UserPasswordMother;
use Tests\Api\User\Domain\ObjectMother\UserUuidMother;

final class CreateUserCommandMother
{
	public static function create(
		?UserUuid $uuid = null,
		?UserName $name = null,
		?UserEmail $email = null,
		?UserPassword $password = null
	): CreateUserCommand {
		return new CreateUserCommand(
			$uuid?->value() ?? UserUuidMother::create()->value(),
			$name?->value() ?? UserNameMother::create()->value(),
			$email?->value() ?? UserEmailMother::create()->value(),
			$password?->value() ?? UserPasswordMother::create()->value()
		);
	}
}
