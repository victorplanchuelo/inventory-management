<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Creator;

use Manager\Shared\Domain\Bus\Command\CommandHandler;

final readonly class CreateUserCommandHandler implements CommandHandler
{
	public function __construct(private UserCreator $creator) {}

	public function __invoke(CreateUserCommand $command): void
	{
		$this->creator->__invoke(
			$command->uuid(),
			$command->name(),
			$command->email(),
			$command->password()
		);
	}
}
