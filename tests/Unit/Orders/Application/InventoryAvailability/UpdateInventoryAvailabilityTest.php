<?php

declare(strict_types=1);

namespace Tests\Unit\Orders\Application\InventoryAvailability;

use DateTimeImmutable;
use Manager\Orders\Application\InventoryAvailability\Update\InventoryStockState;
use Manager\Orders\Application\InventoryAvailability\Update\UpdateInventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Orders\OrdersUnitTestCase;

final class UpdateInventoryAvailabilityTest extends OrdersUnitTestCase
{
	#[Test]
	public function it_should_apply_the_incoming_stock_state_to_the_repository(): void
	{
		$productId = UuidMother::create();
		$stockItemId = UuidMother::create();
		$occurredAt = new DateTimeImmutable('2026-11-24T12:00:00Z');
		$applied = null;

		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('apply')
			->once()
			->with(Mockery::on(static function (InventoryAvailability $availability) use (&$applied): bool {
				$applied = $availability;

				return true;
			}));

		(new UpdateInventoryAvailability($repository))(new InventoryStockState(
			$productId,
			$stockItemId,
			10,
			3,
			$occurredAt,
		));

		self::assertInstanceOf(InventoryAvailability::class, $applied);
		self::assertSame($productId, $applied->productId());
		self::assertSame($stockItemId, $applied->stockItemId());
		self::assertSame(10, $applied->onHand());
		self::assertSame(3, $applied->reserved());
		self::assertSame(7, $applied->available());
		self::assertSame('2026-11-24T12:00:00Z', $applied->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
		self::assertGreaterThanOrEqual($occurredAt->getTimestamp(), $applied->updatedAt()->getTimestamp());
	}

	#[Test]
	public function it_should_build_the_projection_with_the_event_occurrence_time(): void
	{
		$occurredAt = new DateTimeImmutable('2026-11-24T12:00:00Z');
		$applied = null;

		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('apply')
			->once()
			->with(Mockery::on(static function (InventoryAvailability $availability) use (&$applied): bool {
				$applied = $availability;

				return true;
			}));

		(new UpdateInventoryAvailability($repository))(new InventoryStockState(
			UuidMother::create(),
			UuidMother::create(),
			10,
			0,
			$occurredAt,
		));

		self::assertInstanceOf(InventoryAvailability::class, $applied);
		self::assertSame('2026-11-24T12:00:00Z', $applied->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
	}

	#[Test]
	public function it_should_propagate_repository_failures(): void
	{
		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('apply')
			->once()
			->andThrow(new RuntimeException('repository failed'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('repository failed');

		(new UpdateInventoryAvailability($repository))(new InventoryStockState(
			UuidMother::create(),
			UuidMother::create(),
			10,
			0,
			new DateTimeImmutable('2026-11-24T12:00:00Z'),
		));
	}
}
