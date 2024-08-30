<?php

declare(strict_types=1);

namespace Tests\Unit\User\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Manager\Application\User\Creator\CreateUserCommandHandler;
use Manager\Application\User\Creator\UserCreator;
use Manager\Application\User\Searcher\AllUsersSearcher;
use Manager\Application\User\Searcher\GetAllUsersQuery;
use Manager\Application\User\Searcher\GetAllUsersQueryHandler;
use Manager\Domain\User\ValueObjects\UserId;
use PHPUnit\Framework\Attributes\Test;
use Tests\ObjectMothers\User\UserMother;
use Tests\Unit\User\UsersModuleUnitTestCase;
use function Lambdish\Phunctional\map;

final class GetAllUsersQueryHandlerTest extends UsersModuleUnitTestCase
{
    use RefreshDatabase;

    private GetAllUsersQueryHandler|null $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new GetAllUsersQueryHandler(new AllUsersSearcher($this->repository()));
    }

    #[Test] public function it_should_find_an_existing_courses_counter(): void
    {
        $user1 = UserMother::create(new UserId(1));
        $user2 = UserMother::create(new UserId(2));

        $users = [$user1, $user2];

        $arrUsersResponse = [];
        foreach ($users as $user) {
            $userResponseMother = UserResponseMother::create(
                $user->id()->value(),
                $user->name()->value(),
                $user->email()->value(),
                $user->password()->value()
            );
            $arrUsersResponse[] = $userResponseMother;
        }

        $query = new GetAllUsersQuery();
        $response = UsersResponseMother::create(
            ...map(function ($user) {
                return UserResponseMother::create(
                    $user->id()->value(),
                    $user->name()->value(),
                    $user->email()->value(),
                    $user->password()->value()
                );
            }, $users));

        $this->shouldSearchAll($users);
        $this->assertAskResponse($response, $query, $this->handler);
    }


}