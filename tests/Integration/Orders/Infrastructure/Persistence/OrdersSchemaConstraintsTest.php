<?php

declare(strict_types=1);

namespace Tests\Integration\Orders\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Orders\OrdersIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;

final class OrdersSchemaConstraintsTest extends OrdersIntegrationTestCase
{
	#[Test]
	public function it_should_reject_a_negative_on_hand_value(): void
	{
		$this->expectException(QueryException::class);

		$this->insertProjection($this->newProductId(), $this->newStockItemId(), -1);
	}

	#[Test]
	public function it_should_reject_a_negative_reserved_value(): void
	{
		$this->expectException(QueryException::class);

		$this->insertProjection($this->newProductId(), $this->newStockItemId(), 10, -1);
	}

	#[Test]
	public function it_should_reject_reserved_greater_than_on_hand(): void
	{
		$this->expectException(QueryException::class);

		$this->insertProjection($this->newProductId(), $this->newStockItemId(), 5, 6);
	}

	#[Test]
	public function it_should_enforce_one_projection_per_product(): void
	{
		$productId = $this->newProductId();
		$this->insertProjection($productId, $this->newStockItemId(), 10);

		$this->expectException(QueryException::class);

		$this->insertProjection($productId, $this->newStockItemId(), 5);
	}

	#[Test]
	public function it_should_create_the_projection_table_without_available_or_aggregate_version(): void
	{
		$columns = $this->connection->getSchemaBuilder()->getColumnListing('orders_inventory_availability');

		self::assertSame([
			'product_id',
			'stock_item_id',
			'on_hand',
			'reserved',
			'source_occurred_at',
			'updated_at',
		], $columns);

		self::assertFalse($this->connection->getSchemaBuilder()->hasColumn('orders_inventory_availability', 'available'));
		self::assertFalse(
			$this->connection->getSchemaBuilder()->hasColumn('orders_inventory_availability', 'aggregate_version')
		);
	}

	#[Test]
	public function it_should_create_the_orders_inbox_table_with_the_task004_schema(): void
	{
		$builder = $this->connection->getSchemaBuilder();

		self::assertSame([
			'id',
			'consumer',
			'event_id',
			'event_name',
			'event_version',
			'aggregate_type',
			'aggregate_id',
			'aggregate_version',
			'occurred_at',
			'processed_at',
			'outcome',
			'correlation_id',
			'causation_id',
			'created_at',
		], $builder->getColumnListing('orders_inbox'));

		$indexes = [];

		foreach ($builder->getIndexes('orders_inbox') as $index) {
			$indexes[$index['name']] = $index;
		}

		self::assertTrue($indexes['primary']['primary']);
		self::assertSame(['id'], $indexes['primary']['columns']);
		self::assertTrue($indexes['orders_inbox_consumer_event_id_unique']['unique']);
		self::assertSame(['consumer', 'event_id'], $indexes['orders_inbox_consumer_event_id_unique']['columns']);
		self::assertSame(
			['consumer', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
			$indexes['orders_inbox_consumer_aggregate_version_index']['columns']
		);
		self::assertSame(['consumer', 'processed_at'], $indexes['orders_inbox_consumer_processed_at_index']['columns']);
		self::assertSame(['consumer', 'outcome'], $indexes['orders_inbox_consumer_outcome_index']['columns']);

		$constraint = $this->connection->selectOne(
			'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
			. 'WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?',
			[$this->connection->getDatabaseName(), 'orders_inbox', 'CHECK', 'orders_inbox_processing_state_is_consistent', ]
		);

		self::assertNotNull($constraint);
	}

	private function insertProjection(string $productId, string $stockItemId, int $onHand, int $reserved = 0): void
	{
		$this->trackProduct($productId);

		$this->connection->table('orders_inventory_availability')->insert([
			'product_id' => $productId,
			'stock_item_id' => $stockItemId,
			'on_hand' => $onHand,
			'reserved' => $reserved,
			'source_occurred_at' => '2026-11-24 12:00:00',
			'updated_at' => '2026-11-24 12:00:01',
		]);
	}

	private function newProductId(): string
	{
		return UuidMother::create();
	}

	private function newStockItemId(): string
	{
		return UuidMother::create();
	}
}
