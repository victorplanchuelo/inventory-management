<?php

declare(strict_types=1);

namespace Tests\Unit\User\Create;

use Manager\Application\User\Creator\CreateUserCommand;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;
use Tests\ObjectMothers\User\UserEmailMother;
use Tests\ObjectMothers\User\UserNameMother;
use Tests\ObjectMothers\User\UserPasswordMother;

final class CreateUserCommandMother
{
	public static function create(
		?UserName $name = null,
		?UserEmail $email = null,
        ?UserPassword $password = null
	): CreateUserCommand {
		return new CreateUserCommand(
			$name?->value() ?? UserNameMother::create()->value(),
			$email?->value() ?? UserEmailMother::create()->value(),
			$password?->value() ?? UserPasswordMother::create()->value()
		);
	}
}
