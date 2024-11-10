<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain;

use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserId;
use Manager\Api\User\Domain\ValueObjects\UserName;
use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Manager\Api\User\Domain\ValueObjects\UserUuid;
use Manager\Shared\Domain\Aggregate\AggregateRoot;

final class User extends AggregateRoot
{
	public function __construct(
		protected readonly UserId $id,
		protected readonly UserUuid $uuid,
		protected readonly UserName $name,
		protected readonly UserEmail $email,
		protected readonly UserPassword $password
	) {}

	public static function create(string $uuid, ?int $id, string $name, string $email, string $password): self
	{
        return new self(
            new UserId($id ?? 0),
            new UserUuid($uuid),
            new UserName($name),
            new UserEmail($email),
            new UserPassword($password)
        );
	}

    public function pushDomainEvent(): void
    {
        $this->record(
            new UserCreatedDomainEvent(
                $this->id->value(),
                $this->uuid->value(),
                $this->name->value(),
                $this->email->value(),
                $this->password->value()
            )
        );
    }

	public static function fromPrimitives(array $primitives): self
	{
		return new self(
			$primitives['uuid'],
			$primitives['id'],
			$primitives['name'],
			$primitives['email'],
			$primitives['password']
		);
	}

	public function toPrimitives(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
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

	public function uuid(): UserUuid
	{
		return $this->uuid;
	}
}
