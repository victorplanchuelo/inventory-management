<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Update;

use DateTimeImmutable;

final readonly class InventoryStockState
{
	public function __construct(
		public string $productId,
		public string $stockItemId,
		public int $onHand,
		public int $reserved,
		public DateTimeImmutable $occurredAt,
	) {}
}
