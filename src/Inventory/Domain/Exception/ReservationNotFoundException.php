<?php

declare(strict_types=1);

namespace Manager\Inventory\Domain\Exception;

use Manager\Inventory\Domain\ValueObject\ReservationId;
use Manager\Shared\Domain\DomainError;

final class ReservationNotFoundException extends DomainError
{
	public function __construct(private readonly ReservationId $reservationId)
	{
		parent::__construct();
	}

	public function errorCode(): string
	{
		return 'reservation_not_found';
	}

	protected function errorMessage(): string
	{
		return sprintf('A reservation with ID <%s> was not found.', $this->reservationId->value());
	}
}
