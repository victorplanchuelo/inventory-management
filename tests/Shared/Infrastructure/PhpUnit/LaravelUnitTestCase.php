<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\PhpUnit;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Shared\TestCase;

final class LaravelUnitTestCase extends TestCase
{
	use MockeryPHPUnitIntegration;
}
