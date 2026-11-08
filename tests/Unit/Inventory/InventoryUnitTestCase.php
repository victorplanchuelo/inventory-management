<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class InventoryUnitTestCase extends TestCase
{
	use MockeryPHPUnitIntegration;
}
