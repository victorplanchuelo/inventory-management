<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReleaseStock;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class ReleaseStockCommand implements Command
{
	public function __construct(public string $reservationId) {}

	public function reservationId(): string
	{
		return $this->reservationId;
	}
}
