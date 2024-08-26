<?php

declare(strict_types=1);

namespace Manager\Application\User\Commands;

use Illuminate\Support\Facades\Hash;
use Manager\Application\User\Creator\UserCreator;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;
use Manager\Shared\Domain\Bus\Command\CommandHandler;

final readonly class PostCreateUserCommandHandler implements CommandHandler
{
	public function __construct(private UserCreator $creator) {}

	public function __invoke(PostCreateUserCommand $command): void
	{
		$name = new UserName($command->name());
		$email = new UserEmail($command->email());
		$password = new UserPassword(Hash::make($command->password()));

		$this->creator->__invoke($name, $email, $password);
	}
}
