<?php

declare(strict_types=1);

namespace Manager\Domain\User;

use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserId;
use Manager\Domain\User\ValueObjects\UserName;
use Manager\Domain\User\ValueObjects\UserPassword;
use Manager\Shared\Domain\Aggregate\AggregateRoot;

final class User extends AggregateRoot
{
	protected readonly UserId $id;
	protected readonly UserName $name;
	protected readonly UserEmail $email;
	protected readonly UserPassword $password;

	public function __construct(
		UserId $id,
		UserName $name,
		UserEmail $email,
		UserPassword $password
	) {
		$this->id = $id;
		$this->name = $name;
		$this->email = $email;
		$this->password = $password;
	}

	public static function create(UserName $name, UserEmail $email, UserPassword $password): self
	{
		return new self(new UserId(0), $name, $email, $password);
	}

	public static function fromPrimitives(array $primitives): self
	{
		return new self($primitives['id'], $primitives['name'], $primitives['email'], $primitives['password']);
	}

	public function toPrimitives(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'email' => $this->email,
			'password' => $this->password,
		];
	}

	public function password(): UserPassword
	{
		return $this->password;
	}

	public function email(): UserEmail
	{
		return $this->email;
	}

	public function name(): UserName
	{
		return $this->name;
	}

	public function id(): UserId
	{
		return $this->id;
	}
}
