<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain;

use Manager\Shared\Domain\Bus\Event\DomainEvent;

final class UserCreatedDomainEvent extends DomainEvent
{
	public function __construct(
		int $id,
		private readonly string $uuid,
		private readonly string $name,
		private readonly string $email,
		private readonly string $password,
		string $eventId = null,
		string $occurredOn = null
	) {
		parent::__construct($id, $eventId, $occurredOn);
	}

	public static function eventName(): string
	{
		return 'user.created';
	}

	public static function fromPrimitives(
		int $aggregateId,
		array $body,
		string $eventId,
		string $occurredOn
	): DomainEvent {
		return new self($aggregateId, $body['uuid'], $body['name'], $body['email'], $body['password'], $eventId, $occurredOn);
	}

	public function toPrimitives(): array
	{
		return [
			'uuid' => $this->uuid,
			'name' => $this->name,
			'email' => $this->email,
			'password' => $this->password,
		];
	}

	public function uuid(): string
	{
		return $this->uuid;
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
