<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Manager\Infrastructure\User\MySqlUserRepository;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

abstract class UserInfrastructureTestCase extends InfrastructureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function mySqlRepository(): MySqlUserRepository
	{
		return new MySqlUserRepository();
	}
}
