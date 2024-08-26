<?php

namespace Tests\Integration\User;


class MySqlUserRepositoryTest extends UserInfrastructureTestCase
{
    public function test_it_should_list_all_users(): void
    {
        $users = $this->mySqlRepository()->searchAll();
        $this->ass
    }
}
