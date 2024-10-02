<?php

declare(strict_types=1);

namespace Tests\Api\User\Infrastructure;

use Manager\Api\User\Infrastructure\MySqlUserRepository;
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
