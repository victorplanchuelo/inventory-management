<?php

declare(strict_types=1);

namespace Tests\Api\User\Infrastructure;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Api\User\Domain\ObjectMother\UserIdMother;
use Tests\Api\User\Domain\ObjectMother\UserMother;

class MySqlUserRepositoryTest extends UserInfrastructureTestCase
{
	use DatabaseMigrations;

    public function test_it_should_list_all_users(): void
	{
		$user = UserMother::create(id: UserIdMother::create(1));
		$anotherUser = UserMother::create(id: UserIdMother::create(2));
		$existingUsers = [$user, $anotherUser];

		$this->mySqlRepository()->save($user);
        $this->mySqlRepository()->save($anotherUser);

		//$this->assertEquals($existingUsers, $this->mySqlRepository()->searchAll());
		$this->assertSimilar($existingUsers, $this->mySqlRepository()->searchAll());
	}

	public function test_it_should_create_a_user(): void
	{
		$this->mySqlRepository()->save(UserMother::create());
	}
}
