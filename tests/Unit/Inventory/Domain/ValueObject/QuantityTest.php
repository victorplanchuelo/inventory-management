<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Domain\ValueObject;

use Manager\Inventory\Domain\Exception\InvalidQuantityException;
use Manager\Inventory\Domain\ValueObject\Quantity;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class QuantityTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_accept_zero(): void
	{
		$quantity = new Quantity(0);

		self::assertSame(0, $quantity->value());
		self::assertFalse($quantity->isPositive());
	}

	#[Test]
	public function it_should_accept_positive_values(): void
	{
		$quantity = new Quantity(10);

		self::assertSame(10, $quantity->value());
		self::assertTrue($quantity->isPositive());
	}

	#[Test]
	public function it_should_reject_negative_values(): void
	{
		$this->expectException(InvalidQuantityException::class);

		new Quantity(-1);
	}
}
