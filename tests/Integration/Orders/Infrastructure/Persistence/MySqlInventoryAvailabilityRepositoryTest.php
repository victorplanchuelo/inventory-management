<?php

declare(strict_types=1);

namespace Tests\Integration\Orders\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Orders\OrdersIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;

final class MySqlInventoryAvailabilityRepositoryTest extends OrdersIntegrationTestCase
{
	private InventoryAvailabilityRepository $repository;

	protected function setUp(): void
	{
		parent::setUp();

		$this->repository = $this->service(InventoryAvailabilityRepository::class);
	}

	#[Test]
	public function it_should_insert_a_new_projection(): void
	{
		$availability = $this->availability();

		$this->connection->transaction(fn () => $this->repository->apply($availability));

		$row = $this->connection->table('orders_inventory_availability')
			->where('product_id', $availability->productId())
			->first();

		self::assertNotNull($row);
		self::assertSame($availability->productId(), $row->product_id);
		self::assertSame($availability->stockItemId(), $row->stock_item_id);
		self::assertSame(10, (int) $row->on_hand);
		self::assertSame(3, (int) $row->reserved);
		self::assertSame('2026-11-24 12:00:00', $row->source_occurred_at);
		self::assertSame('2026-11-24 12:00:01', $row->updated_at);
	}

	#[Test]
	public function it_should_update_an_existing_projection(): void
	{
		$availability = $this->availability();
		$this->connection->transaction(fn () => $this->repository->apply($availability));

		$updated = new InventoryAvailability(
			$availability->productId(),
			$availability->stockItemId(),
			7,
			1,
			new DateTimeImmutable('2026-11-24T13:00:00Z'),
			new DateTimeImmutable('2026-11-24T13:00:01Z'),
		);

		$this->connection->transaction(fn () => $this->repository->apply($updated));

		$rows = $this->connection->table('orders_inventory_availability')
			->where('product_id', $availability->productId())
			->get();

		self::assertCount(1, $rows);
		self::assertSame(7, (int) $rows[0]->on_hand);
		self::assertSame(1, (int) $rows[0]->reserved);
		self::assertSame('2026-11-24 13:00:00', $rows[0]->source_occurred_at);
		self::assertSame('2026-11-24 13:00:01', $rows[0]->updated_at);
	}

	#[Test]
	public function it_should_find_a_projection_by_product_id(): void
	{
		$availability = $this->availability();
		$this->connection->transaction(fn () => $this->repository->apply($availability));

		$found = $this->repository->findByProductId($availability->productId());

		self::assertNotNull($found);
		self::assertSame($availability->productId(), $found->productId());
		self::assertSame($availability->stockItemId(), $found->stockItemId());
		self::assertSame(10, $found->onHand());
		self::assertSame(3, $found->reserved());
		self::assertSame(7, $found->available());
		self::assertSame('2026-11-24T12:00:00Z', $found->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
		self::assertSame('2026-11-24T12:00:01Z', $found->updatedAt()->format('Y-m-d\TH:i:s\Z'));
		self::assertSame('UTC', $found->sourceOccurredAt()->getTimezone()->getName());
		self::assertSame('UTC', $found->updatedAt()->getTimezone()->getName());
	}

	#[Test]
	public function it_should_return_null_for_an_unknown_product_id(): void
	{
		self::assertNull($this->repository->findByProductId(UuidMother::create()));
	}

	#[Test]
	public function it_should_reject_an_apply_outside_an_active_transaction(): void
	{
		$availability = $this->availability();

		try {
			$this->repository->apply($availability);
			self::fail('A LogicException was expected.');
		} catch (LogicException $exception) {
			self::assertSame(
				'Inventory availability projection writes require an active transaction.',
				$exception->getMessage()
			);
		}

		self::assertNull($this->repository->findByProductId($availability->productId()));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_store_timestamps_in_utc_when_the_mysql_session_timezone_is_not_utc(): void
	{
		$availability = $this->availability();

		$this->withSessionTimeZone('+09:00', function () use ($availability): void {
			$this->connection->transaction(fn () => $this->repository->apply($availability));
		});

		$row = $this->connection->table('orders_inventory_availability')
			->where('product_id', $availability->productId())
			->first();

		self::assertNotNull($row);
		self::assertSame('2026-11-24 12:00:00', $row->source_occurred_at);
		self::assertSame('2026-11-24 12:00:01', $row->updated_at);

		$found = $this->repository->findByProductId($availability->productId());

		self::assertNotNull($found);
		self::assertSame('2026-11-24T12:00:00Z', $found->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
		self::assertSame('2026-11-24T12:00:01Z', $found->updatedAt()->format('Y-m-d\TH:i:s\Z'));
	}

	private function availability(): InventoryAvailability
	{
		$productId = UuidMother::create();
		$this->trackProduct($productId);

		return new InventoryAvailability(
			$productId,
			UuidMother::create(),
			10,
			3,
			new DateTimeImmutable('2026-11-24T12:00:00Z', new DateTimeZone('UTC')),
			new DateTimeImmutable('2026-11-24T12:00:01Z', new DateTimeZone('UTC')),
		);
	}
}
