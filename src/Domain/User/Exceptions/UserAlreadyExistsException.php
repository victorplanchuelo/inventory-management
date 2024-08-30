<?php

declare(strict_types=1);

namespace Manager\Domain\User\Exceptions;

use Manager\Domain\User\ValueObjects\UserId;
use Manager\Shared\Domain\DomainError;

final class UserAlreadyExistsException extends DomainError
{
	public function __construct(private readonly UserId $userId)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'user_already_exists';
	}

	protected function errorMessage(): string
	{
		return sprintf('The user <%s> already exists.', $this->userId->value());
	}
}