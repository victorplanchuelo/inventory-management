<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Update\Exception;

use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;

final class InvalidInventoryStockEvent extends PermanentIntegrationEventFailure
{
	public static function becauseAggregateTypeIsInvalid(string $aggregateType): self
	{
		return new self(sprintf('Unsupported Inventory stock aggregate type <%s>.', $aggregateType));
	}

	public static function becauseAggregateIdDoesNotMatch(string $aggregateId, string $stockItemId): self
	{
		return new self(sprintf(
			'The Inventory stock aggregate identifier <%s> does not match the payload stock item identifier <%s>.',
			$aggregateId,
			$stockItemId
		));
	}

	public static function becauseFieldIsMissing(string $field): self
	{
		return new self(sprintf('The Inventory stock payload is missing the <%s> field.', $field));
	}

	public static function becauseFieldIsInvalid(string $field): self
	{
		return new self(sprintf('The Inventory stock payload contains an invalid <%s> field.', $field));
	}

	public static function becauseQuantityIsNegative(string $field, int $value): self
	{
		return new self(sprintf('The Inventory stock payload <%s> quantity <%d> cannot be negative.', $field, $value));
	}

	public static function becauseReservedExceedsOnHand(int $reserved, int $onHand): self
	{
		return new self(sprintf(
			'The Inventory stock payload reserved quantity <%d> cannot be greater than the on-hand quantity <%d>.',
			$reserved,
			$onHand
		));
	}

	public static function becauseAvailableDoesNotMatch(int $available, int $expected): self
	{
		return new self(sprintf(
			'The Inventory stock payload available quantity <%d> does not match the expected value <%d>.',
			$available,
			$expected
		));
	}
}
