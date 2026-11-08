<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Shared\Domain\DomainError;

final class InvalidQuantityException extends DomainError
{
	public function __construct(private readonly int $value)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'invalid_quantity';
	}

	protected function errorMessage(): string
	{
		return sprintf('The quantity <%d> cannot be negative.', $this->value);
	}
}
