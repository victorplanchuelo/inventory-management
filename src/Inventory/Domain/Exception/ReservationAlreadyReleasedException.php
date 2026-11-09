<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Shared\Domain\DomainError;

final class ReservationAlreadyReleasedException extends DomainError
{
	public function __construct(private readonly ReservationId $reservationId)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'reservation_already_released';
	}

	protected function errorMessage(): string
	{
		return sprintf('A reservation with ID <%s> has already been released.', $this->reservationId->value());
	}
}
