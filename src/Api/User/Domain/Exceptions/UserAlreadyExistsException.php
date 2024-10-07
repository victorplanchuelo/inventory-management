<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain\Exceptions;

use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Shared\Domain\DomainError;

final class UserAlreadyExistsException extends DomainError
{
	public function __construct(private readonly UserEmail $userEmail)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'user_already_exists';
	}

	protected function errorMessage(): string
	{
		return sprintf('The user with email <%s> already exists.', $this->userEmail->value());
	}
}
