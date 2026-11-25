<?php

declare(strict_types=1);

namespace Tests\Unit\Orders\Domain\InventoryAvailability;

use DateTimeImmutable;
use InvalidArgumentException;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Unit\Orders\OrdersUnitTestCase;

final class InventoryAvailabilityTest extends OrdersUnitTestCase
{
	#[Test]
	public function it_should_derive_available_from_on_hand_and_reserved(): void
	{
		$availability = $this->availability(10, 4);

		self::assertSame(10, $availability->onHand());
		self::assertSame(4, $availability->reserved());
		self::assertSame(6, $availability->available());
	}

	#[Test]
	public function it_should_reject_negative_on_hand(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->availability(-1, 0);
	}

	#[Test]
	public function it_should_reject_negative_reserved(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->availability(10, -1);
	}

	#[Test]
	public function it_should_reject_reserved_greater_than_on_hand(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->availability(5, 6);
	}

	#[Test]
	public function it_should_reject_invalid_identifiers(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new InventoryAvailability(
			'not-a-uuid',
			UuidMother::create(),
			10,
			0,
			new DateTimeImmutable('2026-11-24T12:00:00Z'),
			new DateTimeImmutable('2026-11-24T12:00:01Z'),
		);
	}

	#[Test]
	public function it_should_normalize_timestamps_to_utc(): void
	{
		$availability = new InventoryAvailability(
			UuidMother::create(),
			UuidMother::create(),
			10,
			0,
			new DateTimeImmutable('2026-11-24T14:00:00+02:00'),
			new DateTimeImmutable('2026-11-24T15:00:00+02:00'),
		);

		self::assertSame('UTC', $availability->sourceOccurredAt()->getTimezone()->getName());
		self::assertSame('UTC', $availability->updatedAt()->getTimezone()->getName());
		self::assertSame('2026-11-24T12:00:00Z', $availability->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
		self::assertSame('2026-11-24T13:00:00Z', $availability->updatedAt()->format('Y-m-d\TH:i:s\Z'));
	}

	private function availability(int $onHand, int $reserved): InventoryAvailability
	{
		return new InventoryAvailability(
			UuidMother::create(),
			UuidMother::create(),
			$onHand,
			$reserved,
			new DateTimeImmutable('2026-11-24T12:00:00Z'),
			new DateTimeImmutable('2026-11-24T12:00:01Z'),
		);
	}
}
