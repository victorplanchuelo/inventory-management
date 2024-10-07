<?php

declare(strict_types=1);

namespace Tests\Api\User\Application\SearchByCriteria;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Manager\Api\User\Application\SearchByCriteria\SearchUserByCriteriaQuery;
use Manager\Api\User\Application\SearchByCriteria\SearchUserByCriteriaQueryHandler;
use Manager\Api\User\Application\SearchByCriteria\UserByCriteriaSearcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\User\Application\Searcher\UserResponseMother;
use Tests\Api\User\Application\Searcher\UsersResponseMother;
use Tests\Api\User\Application\UsersModuleUnitTestCase;
use Tests\Api\User\Domain\ObjectMother\UserIdMother;
use Tests\Api\User\Domain\ObjectMother\UserMother;
use Tests\Api\User\Domain\UserCriteriaMother;
use Tests\Shared\Domain\EmailMother;
use function Lambdish\Phunctional\map;

final class SearchUsersByCriteriaQueryHandlerTest extends UsersModuleUnitTestCase
{
	use RefreshDatabase;

	private SearchUserByCriteriaQueryHandler | null $handler;

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new SearchUserByCriteriaQueryHandler(new UserByCriteriaSearcher($this->repository()));
	}

	#[Test] public function it_should_find_existing_users_by_criteria(): void
	{
		$user1 = UserMother::create(id: UserIdMother::create(), email: EmailMother::set('victor.planchuelo@motocard.com'));
		$user2 = UserMother::create(id: UserIdMother::create(), email: EmailMother::set('victor@motocard.com'));

		$users = [$user1, $user2];

        $filters = [
            [
                'field' => 'email',
                'operator' => 'CONTAINS',
                'value' => 'victor',
            ]
        ];

		$query = new SearchUserByCriteriaQuery(filters: $filters, orderBy: null, order: null, limit: null, offset: null);
		$response = UsersResponseMother::create(
			...map(function ($user) {
				return UserResponseMother::create(
					$user->id()->value(),
					$user->uuid()->value(),
					$user->name()->value(),
					$user->email()->value(),
					$user->password()->value()
				);
			}, $users)
		);


        $criteria = UserCriteriaMother::emailContains('victor');

        $this->shouldSearchByCriteriaAndReturnUsers($criteria, $users);
		$this->assertAskResponse($response, $query, $this->handler);
	}
}
