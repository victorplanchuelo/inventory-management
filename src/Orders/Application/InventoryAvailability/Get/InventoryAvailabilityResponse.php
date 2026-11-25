<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Get;

use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Shared\Domain\Bus\Query\Response;

final readonly class InventoryAvailabilityResponse implements Response
{
	private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	public function __construct(
		private string $productId,
		private string $stockItemId,
		private int $onHand,
		private int $reserved,
		private int $available,
		private string $sourceOccurredAt,
		private string $projectionUpdatedAt,
	) {}

	public static function fromInventoryAvailability(InventoryAvailability $availability): self
	{
		return new self(
			$availability->productId(),
			$availability->stockItemId(),
			$availability->onHand(),
			$availability->reserved(),
			$availability->available(),
			$availability->sourceOccurredAt()->format(self::DATE_FORMAT),
			$availability->updatedAt()->format(self::DATE_FORMAT),
		);
	}

	public function productId(): string
	{
		return $this->productId;
	}

	public function stockItemId(): string
	{
		return $this->stockItemId;
	}

	public function onHand(): int
	{
		return $this->onHand;
	}

	public function reserved(): int
	{
		return $this->reserved;
	}

	public function available(): int
	{
		return $this->available;
	}

	public function sourceOccurredAt(): string
	{
		return $this->sourceOccurredAt;
	}

	public function projectionUpdatedAt(): string
	{
		return $this->projectionUpdatedAt;
	}
}
