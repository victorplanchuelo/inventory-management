<?php

namespace Tests\Integration\User;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ObjectMothers\User\UserIdMother;
use Tests\ObjectMothers\User\UserMother;

class MySqlUserRepositoryTest extends UserInfrastructureTestCase
{
    use RefreshDatabase;

    public function test_it_should_list_all_users(): void
    {
        $user = UserMother::create(id: UserIdMother::create(1));
        $anotherUser = UserMother::create(id: UserIdMother::create(2));

        $userSaved1 = $this->mySqlRepository()->create($user);
        $userSaved2 = $this->mySqlRepository()->create($anotherUser);

        $existingUsers = [$userSaved1, $userSaved2];

        $this->assertEquals($existingUsers, $this->mySqlRepository()->searchAll());
        //$this->assertSimilar($existingUsers, $this->mySqlRepository()->searchAll());
    }

    public function test_it_should_create_a_user(): void
    {
        $user = UserMother::create(id: UserIdMother::create(3));
        $userSaved = $this->mySqlRepository()->create($user);
        $this->assertEquals($user->id(), $userSaved->id());
    }
}
