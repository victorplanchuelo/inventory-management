<?php

declare(strict_types=1);

namespace Manager\Application\User\Creator;

use Illuminate\Support\Facades\Hash;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;
use Manager\Shared\Domain\Bus\Command\CommandHandler;

final readonly class CreateUserCommandHandler implements CommandHandler
{
	public function __construct(private UserCreator $creator) {}

	public function __invoke(CreateUserCommand $command): void
	{
		$name = new UserName($command->name());
		$email = new UserEmail($command->email());
		$password = new UserPassword($command->password());

		$this->creator->__invoke($name, $email, $password);
	}
}
