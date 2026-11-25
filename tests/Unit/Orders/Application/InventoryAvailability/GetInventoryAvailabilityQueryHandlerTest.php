<?php

declare(strict_types=1);

namespace Tests\Unit\Orders\Application\InventoryAvailability;

use DateTimeImmutable;
use Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQuery;
use Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQueryHandler;
use Manager\Orders\Application\InventoryAvailability\Get\InventoryAvailabilityResponse;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Orders\OrdersUnitTestCase;

final class GetInventoryAvailabilityQueryHandlerTest extends OrdersUnitTestCase
{
	#[Test]
	public function it_should_return_the_projection_response(): void
	{
		$productId = UuidMother::create();
		$availability = new InventoryAvailability(
			$productId,
			UuidMother::create(),
			10,
			3,
			new DateTimeImmutable('2026-11-24T12:00:00Z'),
			new DateTimeImmutable('2026-11-24T12:00:01Z'),
		);

		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('findByProductId')
			->once()
			->with($productId)
			->andReturn($availability);

		$response = (new GetInventoryAvailabilityQueryHandler($repository))(
			new GetInventoryAvailabilityQuery($productId)
		);

		self::assertInstanceOf(InventoryAvailabilityResponse::class, $response);
		self::assertSame($productId, $response->productId());
		self::assertSame($availability->stockItemId(), $response->stockItemId());
		self::assertSame(10, $response->onHand());
		self::assertSame(3, $response->reserved());
		self::assertSame(7, $response->available());
		self::assertSame('2026-11-24T12:00:00Z', $response->sourceOccurredAt());
		self::assertSame('2026-11-24T12:00:01Z', $response->projectionUpdatedAt());
	}

	#[Test]
	public function it_should_return_null_when_the_projection_does_not_exist(): void
	{
		$productId = UuidMother::create();

		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('findByProductId')
			->once()
			->with($productId)
			->andReturnNull();

		$response = (new GetInventoryAvailabilityQueryHandler($repository))(
			new GetInventoryAvailabilityQuery($productId)
		);

		self::assertNull($response);
	}

	#[Test]
	public function it_should_format_timestamps_in_utc_iso_8601(): void
	{
		$availability = new InventoryAvailability(
			UuidMother::create(),
			UuidMother::create(),
			10,
			0,
			new DateTimeImmutable('2026-11-24T14:00:00+02:00'),
			new DateTimeImmutable('2026-11-24T15:00:00+02:00'),
		);

		$repository = $this->mockClass(InventoryAvailabilityRepository::class);
		$repository->shouldReceive('findByProductId')
			->once()
			->andReturn($availability);

		$response = (new GetInventoryAvailabilityQueryHandler($repository))(
			new GetInventoryAvailabilityQuery($availability->productId())
		);

		self::assertInstanceOf(InventoryAvailabilityResponse::class, $response);
		self::assertSame('2026-11-24T12:00:00Z', $response->sourceOccurredAt());
		self::assertSame('2026-11-24T13:00:00Z', $response->projectionUpdatedAt());
	}
}
