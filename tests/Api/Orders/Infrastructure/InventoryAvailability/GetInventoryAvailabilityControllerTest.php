<?php

declare(strict_types=1);

namespace Tests\Api\Orders\Infrastructure\InventoryAvailability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Shared\TestCase;

final class GetInventoryAvailabilityControllerTest extends TestCase
{
	use RefreshDatabase;

	#[Test]
	public function it_should_return_the_local_projection(): void
	{
		$productId = UuidMother::create();
		$stockItemId = UuidMother::create();
		$this->seedProjection($productId, $stockItemId);

		$response = $this->getJson('/orders/inventory-availability/' . $productId);

		$response->assertOk();
		$response->assertExactJson([
			'productId' => $productId,
			'stockItemId' => $stockItemId,
			'onHand' => 10,
			'reserved' => 3,
			'available' => 7,
			'sourceOccurredAt' => '2026-11-24T12:00:00Z',
			'projectionUpdatedAt' => '2026-11-24T12:00:01Z',
			'advisory' => true,
			'consistency' => 'eventually_consistent',
		]);
	}

	#[Test]
	public function it_should_return_404_with_an_advisory_problem_when_the_projection_does_not_exist(): void
	{
		$response = $this->getJson('/orders/inventory-availability/' . UuidMother::create());

		$response->assertNotFound();
		$response->assertHeader('Content-Type', 'application/problem+json');
		$response->assertExactJson([
			'type' => 'https://stockflow.example/problems/inventory-availability-not-found',
			'title' => 'Inventory availability projection not found',
			'status' => 404,
			'detail' => 'No Inventory availability projection is available for this product yet. This does not mean that the product has no stock.',
			'advisory' => true,
			'consistency' => 'eventually_consistent',
		]);
	}

	#[Test]
	public function it_should_reject_a_non_uuid_product_id(): void
	{
		$this->getJson('/orders/inventory-availability/not-a-uuid')->assertNotFound();
	}

	#[Test]
	public function it_should_expose_advisory_and_eventual_consistency_metadata(): void
	{
		$productId = UuidMother::create();
		$this->seedProjection($productId, UuidMother::create());

		$body = $this->getJson('/orders/inventory-availability/' . $productId)->json();

		self::assertIsArray($body);
		self::assertTrue($body['advisory']);
		self::assertSame('eventually_consistent', $body['consistency']);
	}

	#[Test]
	public function it_should_not_query_inventory_tables(): void
	{
		$queries = [];

		DB::listen(static function ($query) use (&$queries): void {
			$queries[] = $query->sql;
		});

		$this->getJson('/orders/inventory-availability/' . UuidMother::create())->assertNotFound();

		self::assertNotEmpty($queries);

		foreach ($queries as $sql) {
			self::assertStringNotContainsString('inventory_stock_items', $sql);
			self::assertStringNotContainsString('inventory_stock_reservations', $sql);
			self::assertStringNotContainsString('inventory_inbox', $sql);
		}
	}

	private function seedProjection(string $productId, string $stockItemId): void
	{
		DB::table('orders_inventory_availability')->insert([
			'product_id' => $productId,
			'stock_item_id' => $stockItemId,
			'on_hand' => 10,
			'reserved' => 3,
			'source_occurred_at' => '2026-11-24 12:00:00',
			'updated_at' => '2026-11-24 12:00:01',
		]);
	}
}
