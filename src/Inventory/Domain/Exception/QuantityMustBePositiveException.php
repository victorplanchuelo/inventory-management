<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Shared\Domain\DomainError;

final class QuantityMustBePositiveException extends DomainError
{
	public function __construct(private readonly int $value)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'quantity_must_be_positive';
	}

	protected function errorMessage(): string
	{
		return sprintf('The quantity <%d> must be greater than zero.', $this->value);
	}
}
