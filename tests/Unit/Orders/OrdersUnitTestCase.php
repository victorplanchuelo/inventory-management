<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

abstract class OrdersUnitTestCase extends TestCase
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
}
