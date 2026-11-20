<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\IntegrationEvent;

use DateTimeImmutable;
use LogicException;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Domain\Event\InitialStockRegisteredDomainEvent;
use Manager\Inventory\Domain\Event\StockAddedDomainEvent;
use Manager\Inventory\Domain\Event\StockReservedDomainEvent;
use Manager\Inventory\Domain\ValueObject\StockItemId;
use Manager\Shared\Application\Outbox\OutboxMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockItemIntegrationEventMapperTest extends InventoryUnitTestCase
{
	private const STOCK_ITEM_ID = '0f1e2d3c-4b5a-4c6d-8e7f-901122334455';
	private const PRODUCT_ID = '1a2b3c4d-5e6f-4a7b-9c8d-0e1f2a3b4c5d';
	private const EVENT_ID = '2b3c4d5e-6f7a-4b8c-8d9e-0f1a2b3c4d5e';
	private const ORDER_ID = '3c4d5e6f-7a8b-4c9d-9e0f-1a2b3c4d5e6f';
	private const RESERVATION_ID = '4d5e6f7a-8b9c-4d0e-8f1a-2b3c4d5e6f7a';
	private const OCCURRED_ON = '2026-11-19T10:15:30+00:00';

	#[Test]
	public function it_should_map_initial_stock_registration_to_inventory_stock_registered(): void
	{
		$event = new InitialStockRegisteredDomainEvent(
			new StockItemId(self::STOCK_ITEM_ID),
			self::PRODUCT_ID,
			25,
			self::EVENT_ID,
			self::OCCURRED_ON
		);

		$message = (new StockItemIntegrationEventMapper())->map($event);

		$this->assertCommonEnvelope($message, 'inventory.stock_registered');
		self::assertSame(
			'{"stockItemId":"' . self::STOCK_ITEM_ID . '","productId":"' . self::PRODUCT_ID
			. '","onHand":25,"reserved":0,"available":25,"initialQuantity":25}',
			$message->payloadJson()
		);
	}

	#[Test]
	public function it_should_map_stock_addition_to_inventory_stock_added(): void
	{
		$event = new StockAddedDomainEvent(
			new StockItemId(self::STOCK_ITEM_ID),
			self::PRODUCT_ID,
			5,
			25,
			10,
			self::EVENT_ID,
			self::OCCURRED_ON
		);

		$message = (new StockItemIntegrationEventMapper())->map($event);

		$this->assertCommonEnvelope($message, 'inventory.stock_added');
		self::assertSame(
			'{"stockItemId":"' . self::STOCK_ITEM_ID . '","productId":"' . self::PRODUCT_ID
			. '","onHand":25,"reserved":10,"available":15,"addedQuantity":5}',
			$message->payloadJson()
		);
	}

	#[Test]
	public function it_should_reject_an_unsupported_domain_event(): void
	{
		$event = new StockReservedDomainEvent(
			new StockItemId(self::STOCK_ITEM_ID),
			self::PRODUCT_ID,
			self::ORDER_ID,
			self::RESERVATION_ID,
			5,
			25,
			10,
			self::EVENT_ID,
			self::OCCURRED_ON
		);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Unsupported Inventory domain event');

		(new StockItemIntegrationEventMapper())->map($event);
	}

	private function assertCommonEnvelope(OutboxMessage $message, string $eventName): void
	{
		self::assertSame(self::EVENT_ID, $message->eventId());
		self::assertSame($eventName, $message->eventName());
		self::assertSame('1', $message->eventVersion());
		self::assertSame('inventory', $message->producer());
		self::assertSame('stock_item', $message->aggregateType());
		self::assertSame(self::STOCK_ITEM_ID, $message->aggregateId());
		self::assertNull($message->aggregateVersion());
		self::assertNull($message->correlationId());
		self::assertNull($message->causationId());
		self::assertEquals(new DateTimeImmutable(self::OCCURRED_ON), $message->occurredOn());
	}
}
