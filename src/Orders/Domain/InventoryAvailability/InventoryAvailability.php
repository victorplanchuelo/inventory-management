<?php

declare(strict_types=1);

namespace Manager\Orders\Domain\InventoryAvailability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class InventoryAvailability
{
	private string $productId;
	private string $stockItemId;
	private int $onHand;
	private int $reserved;
	private DateTimeImmutable $sourceOccurredAt;
	private DateTimeImmutable $updatedAt;

	public function __construct(
		string $productId,
		string $stockItemId,
		int $onHand,
		int $reserved,
		DateTimeImmutable $sourceOccurredAt,
		DateTimeImmutable $updatedAt,
	) {
		$this->ensureIsValidUuid('productId', $productId);
		$this->ensureIsValidUuid('stockItemId', $stockItemId);
		$this->ensureIsNonNegative('onHand', $onHand);
		$this->ensureIsNonNegative('reserved', $reserved);

		if ($reserved > $onHand) {
			throw new InvalidArgumentException(sprintf(
				'The reserved quantity <%d> cannot be greater than the on-hand quantity <%d>.',
				$reserved,
				$onHand
			));
		}

		$this->productId = $productId;
		$this->stockItemId = $stockItemId;
		$this->onHand = $onHand;
		$this->reserved = $reserved;
		$this->sourceOccurredAt = $sourceOccurredAt->setTimezone(new DateTimeZone('UTC'));
		$this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
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
		return $this->onHand - $this->reserved;
	}

	public function sourceOccurredAt(): DateTimeImmutable
	{
		return $this->sourceOccurredAt;
	}

	public function updatedAt(): DateTimeImmutable
	{
		return $this->updatedAt;
	}

	private function ensureIsValidUuid(string $field, string $value): void
	{
		try {
			new SimpleUuid($value);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException(sprintf('The <%s> value is not a valid UUID.', $field));
		}
	}

	private function ensureIsNonNegative(string $field, int $value): void
	{
		if ($value < 0) {
			throw new InvalidArgumentException(sprintf('The <%s> quantity cannot be negative.', $field));
		}
	}
}
