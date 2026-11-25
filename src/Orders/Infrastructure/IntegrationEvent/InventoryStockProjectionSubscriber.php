<?php

declare(strict_types=1);

namespace Manager\Orders\Infrastructure\IntegrationEvent;

use InvalidArgumentException;
use Manager\Orders\Application\InventoryAvailability\Update\Exception\InvalidInventoryStockEvent;
use Manager\Orders\Application\InventoryAvailability\Update\InventoryStockState;
use Manager\Orders\Application\InventoryAvailability\Update\UpdateInventoryAvailability;
use Manager\Shared\Application\Inbox\AggregateVersionPolicy;
use Manager\Shared\Application\Inbox\NoOrderingAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class InventoryStockProjectionSubscriber implements IntegrationEventSubscriber
{
	private const AGGREGATE_TYPE = 'stock_item';
	private const CONSUMER_NAME = 'orders.inventory_availability_projection';

	public function __construct(private UpdateInventoryAvailability $update) {}

	/**
	 * @return array<string, list<string>>
	 */
	public function subscribedTo(): array
	{
		return [
			'inventory.stock_registered' => ['1'],
			'inventory.stock_added' => ['1'],
			'inventory.stock_reserved' => ['1'],
			'inventory.stock_released' => ['1'],
		];
	}

	public function consumerName(): string
	{
		return self::CONSUMER_NAME;
	}

	public function orderingPolicy(): AggregateVersionPolicy
	{
		return new NoOrderingAggregateVersionPolicy();
	}

	public function __invoke(IntegrationEventEnvelope $event): void
	{
		if ($event->aggregateType !== self::AGGREGATE_TYPE) {
			throw InvalidInventoryStockEvent::becauseAggregateTypeIsInvalid($event->aggregateType);
		}

		$payload = $event->payload;
		$stockItemId = $this->requiredUuid($payload, 'stockItemId');
		$productId = $this->requiredUuid($payload, 'productId');

		if ($event->aggregateId !== $stockItemId) {
			throw InvalidInventoryStockEvent::becauseAggregateIdDoesNotMatch($event->aggregateId, $stockItemId);
		}

		$onHand = $this->requiredInteger($payload, 'onHand');
		$reserved = $this->requiredInteger($payload, 'reserved');
		$available = $this->requiredInteger($payload, 'available');

		if ($onHand < 0) {
			throw InvalidInventoryStockEvent::becauseQuantityIsNegative('onHand', $onHand);
		}

		if ($reserved < 0) {
			throw InvalidInventoryStockEvent::becauseQuantityIsNegative('reserved', $reserved);
		}

		if ($reserved > $onHand) {
			throw InvalidInventoryStockEvent::becauseReservedExceedsOnHand($reserved, $onHand);
		}

		if ($available !== $onHand - $reserved) {
			throw InvalidInventoryStockEvent::becauseAvailableDoesNotMatch($available, $onHand - $reserved);
		}

		($this->update)(new InventoryStockState($productId, $stockItemId, $onHand, $reserved, $event->occurredAt,));
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requiredUuid(array $payload, string $field): string
	{
		$value = $this->requiredString($payload, $field);

		try {
			new SimpleUuid($value);
		} catch (InvalidArgumentException) {
			throw InvalidInventoryStockEvent::becauseFieldIsInvalid($field);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requiredString(array $payload, string $field): string
	{
		if (!array_key_exists($field, $payload)) {
			throw InvalidInventoryStockEvent::becauseFieldIsMissing($field);
		}

		$value = $payload[$field];

		if (!is_string($value) || trim($value) === '') {
			throw InvalidInventoryStockEvent::becauseFieldIsInvalid($field);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requiredInteger(array $payload, string $field): int
	{
		if (!array_key_exists($field, $payload)) {
			throw InvalidInventoryStockEvent::becauseFieldIsMissing($field);
		}

		$value = $payload[$field];

		if (!is_int($value)) {
			throw InvalidInventoryStockEvent::becauseFieldIsInvalid($field);
		}

		return $value;
	}
}
