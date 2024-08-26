<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use CodelyTv\Backoffice\Courses\Infrastructure\Persistence\ElasticsearchBackofficeCourseRepository;
use CodelyTv\Backoffice\Courses\Infrastructure\Persistence\MySqlBackofficeCourseRepository;
use CodelyTv\Tests\Backoffice\Shared\Infraestructure\PhpUnit\BackofficeContextInfrastructureTestCase;
use Doctrine\ORM\EntityManager;
use Manager\Infrastructure\User\MySqlUserRepository;
use Tests\TestCase;

abstract class UserInfrastructureTestCase extends TestCase
{
	protected function mySqlRepository(): MySqlUserRepository
	{
		return new MySqlUserRepository();
	}
}
