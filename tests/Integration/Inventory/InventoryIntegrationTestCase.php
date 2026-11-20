<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Illuminate\Database\Connection;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

abstract class InventoryIntegrationTestCase extends InfrastructureTestCase
{
	protected Connection $connection;

	/** @var list<string> */
	private array $stockItemIds = [];

	/** @var list<string> */
	private array $reservationIds = [];

	/** @var list<string> */
	private array $outboxEventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->stockItemIds = [];
		$this->reservationIds = [];
		$this->outboxEventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();
	}

	protected function tearDown(): void
	{
		try {
			$this->cleanUp();
		} finally {
			parent::tearDown();
		}
	}

	protected function trackStockItem(string $id): void
	{
		$this->stockItemIds[] = $id;
	}

	protected function trackReservation(string $id): void
	{
		$this->reservationIds[] = $id;
	}

	protected function trackOutboxEvent(string $eventId): void
	{
		$this->outboxEventIds[] = $eventId;
	}

	private function cleanUp(): void
	{
		if ($this->outboxEventIds !== []) {
			$this->connection->table('outbox')
				->whereIn('event_id', $this->outboxEventIds)
				->delete();
		}

		if ($this->stockItemIds !== []) {
			$this->connection->table('inventory_stock_reservations')
				->whereIn('stock_item_id', $this->stockItemIds)
				->delete();
		}

		if ($this->reservationIds !== []) {
			$this->connection->table('inventory_stock_reservations')
				->whereIn('id', $this->reservationIds)
				->delete();
		}

		if ($this->stockItemIds !== []) {
			$this->connection->table('inventory_stock_items')
				->whereIn('id', $this->stockItemIds)
				->delete();
		}
	}
}
