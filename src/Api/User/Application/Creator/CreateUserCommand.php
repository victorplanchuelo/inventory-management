<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Creator;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class CreateUserCommand implements Command
{
	public function __construct(public string $uuid, public string $name, public string $email, public string $password) {}

	public function uuid(): string
	{
		return $this->uuid;
	}

	public function password(): string
	{
		return $this->password;
	}

	public function name(): string
	{
		return $this->name;
	}

	public function email(): string
	{
		return $this->email;
	}
}
