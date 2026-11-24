<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\ReserveStockBatch;

use Manager\Shared\Domain\Bus\Command\Command;

final readonly class ReserveStockBatchCommand implements Command
{
	/**
	 * @param list<ReserveStockLine> $lines
	 */
	public function __construct(public string $orderId, public array $lines) {}
}
