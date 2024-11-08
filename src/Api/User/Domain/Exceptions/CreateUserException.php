<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain\Exceptions;

use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Shared\Domain\DomainError;

final class CreateUserException extends DomainError
{
	public function __construct(private readonly UserEmail $email)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'create_user_error';
	}

	protected function errorMessage(): string
	{
		return sprintf('The user with email <%s> cannot be created', $this->email->value());
	}
}
