<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\IntegrationEvent;

use DateTimeImmutable;
use LogicException;
use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Event\ReservationReleasedDomainEvent;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Domain\Bus\Event\DomainEvent;

final readonly class StockItemIntegrationEventMapper
{
	private const PRODUCER = 'inventory';
	private const AGGREGATE_TYPE = 'stock_item';
	private const CONTRACT_VERSION = '1';

	public function map(DomainEvent $event): OutboxMessage
	{
		return match (true) {
			$event instanceof InitialStockRegisteredDomainEvent => $this->mapInitialStockRegistered($event),
			$event instanceof StockAddedDomainEvent => $this->mapStockAdded($event),
			$event instanceof StockReservedDomainEvent => $this->mapStockReserved($event),
			$event instanceof ReservationReleasedDomainEvent => $this->mapReservationReleased($event),
			default => throw new LogicException(sprintf('Unsupported Inventory domain event <%s>.', $event::class)),
		};
	}

	private function mapInitialStockRegistered(InitialStockRegisteredDomainEvent $event): OutboxMessage
	{
		$body = $event->toPrimitives();
		$initialQuantity = $this->intField($body, 'initialQuantity');

		return $this->message($event, 'inventory.stock_registered', [
			'stockItemId' => $this->stringField($body, 'stockItemId'),
			'productId' => $this->stringField($body, 'productId'),
			'onHand' => $initialQuantity,
			'reserved' => 0,
			'available' => $initialQuantity,
			'initialQuantity' => $initialQuantity,
		]);
	}

	private function mapStockAdded(StockAddedDomainEvent $event): OutboxMessage
	{
		$body = $event->toPrimitives();
		$onHand = $this->intField($body, 'onHand');
		$reserved = $this->intField($body, 'reserved');

		return $this->message($event, 'inventory.stock_added', [
			'stockItemId' => $this->stringField($body, 'stockItemId'),
			'productId' => $this->stringField($body, 'productId'),
			'onHand' => $onHand,
			'reserved' => $reserved,
			'available' => $onHand - $reserved,
			'addedQuantity' => $this->intField($body, 'addedQuantity'),
		]);
	}

	private function mapStockReserved(StockReservedDomainEvent $event): OutboxMessage
	{
		$body = $event->toPrimitives();
		$onHand = $this->intField($body, 'onHand');
		$reserved = $this->intField($body, 'reserved');

		return $this->message($event, 'inventory.stock_reserved', [
			'stockItemId' => $this->stringField($body, 'stockItemId'),
			'productId' => $this->stringField($body, 'productId'),
			'onHand' => $onHand,
			'reserved' => $reserved,
			'available' => $onHand - $reserved,
			'orderId' => $this->stringField($body, 'orderId'),
			'reservationId' => $this->stringField($body, 'reservationId'),
			'reservedQuantity' => $this->intField($body, 'reservedQuantity'),
		]);
	}

	private function mapReservationReleased(ReservationReleasedDomainEvent $event): OutboxMessage
	{
		$body = $event->toPrimitives();
		$onHand = $this->intField($body, 'onHand');
		$reserved = $this->intField($body, 'reserved');

		return $this->message($event, 'inventory.stock_released', [
			'stockItemId' => $this->stringField($body, 'stockItemId'),
			'productId' => $this->stringField($body, 'productId'),
			'onHand' => $onHand,
			'reserved' => $reserved,
			'available' => $onHand - $reserved,
			'orderId' => $this->stringField($body, 'orderId'),
			'reservationId' => $this->stringField($body, 'reservationId'),
			'releasedQuantity' => $this->intField($body, 'releasedQuantity'),
		]);
	}

	/**
	 * @param array<string, scalar> $payload
	 */
	private function message(DomainEvent $event, string $eventName, array $payload): OutboxMessage
	{
		return new OutboxMessage(
			$event->eventId(),
			$eventName,
			self::CONTRACT_VERSION,
			self::PRODUCER,
			self::AGGREGATE_TYPE,
			$event->aggregateId(),
			json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			new DateTimeImmutable($event->occurredOn())
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function stringField(array $body, string $field): string
	{
		$value = $body[$field] ?? null;

		if (!is_string($value)) {
			throw new LogicException(sprintf('The Inventory domain event field <%s> must be a string.', $field));
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function intField(array $body, string $field): int
	{
		$value = $body[$field] ?? null;

		if (!is_int($value)) {
			throw new LogicException(sprintf('The Inventory domain event field <%s> must be an integer.', $field));
		}

		return $value;
	}
}
