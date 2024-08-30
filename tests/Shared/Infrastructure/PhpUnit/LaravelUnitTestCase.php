<?php

namespace Tests\Shared\Infrastructure\PhpUnit;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Shared\TestCase;


class LaravelUnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
}
