<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\CompositeExpectation;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

abstract class InventoryUnitTestCase extends TestCase
{
	use MockeryPHPUnitIntegration;

	/**
	 * @template T of object
	 * @param class-string<T> $interface
	 * @return MockInterface&T
	 */
	protected function mockClass(string $interface): MockInterface
	{
		return Mockery::mock($interface);
	}

	protected function expectation(MockInterface $mock, string $method): MockeryExpectation
	{
		$expectation = $mock->shouldReceive($method);

		if (!$expectation instanceof CompositeExpectation) {
			throw new LogicException(sprintf('Unexpected Mockery expectation type <%s>.', get_debug_type($expectation)));
		}

		return new MockeryExpectation($expectation);
	}
}
