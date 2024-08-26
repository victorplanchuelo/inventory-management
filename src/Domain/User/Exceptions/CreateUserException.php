<?php

declare(strict_types=1);

namespace Manager\Domain\User\Exceptions;

use Manager\Domain\User\ValueObjects\UserEmail;
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
