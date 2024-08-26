<?php

declare(strict_types=1);

namespace Manager\Application\User;

final readonly class UserResponse
{
	public function __construct(
		private int $id,
		private string $name,
		private string $email,
		private string $password
	) {}

	public function id(): int
	{
		return $this->id;
	}

	public function name(): string
	{
		return $this->name;
	}

	public function email(): string
	{
		return $this->email;
	}

	public function password(): string
	{
		return $this->password;
	}
}
