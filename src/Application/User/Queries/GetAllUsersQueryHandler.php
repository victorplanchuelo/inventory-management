<?php

declare(strict_types=1);

namespace Manager\Application\User\Queries;

use Manager\Application\User\Searcher\AllUsersSearcher;
use Manager\Application\User\UsersResponse;
use Manager\Shared\Domain\Bus\Query\QueryHandler;

final readonly class GetAllUsersQueryHandler implements QueryHandler
{
	public function __construct(protected AllUsersSearcher $searcher) {}

	public function __invoke(GetAllUsersQuery $query): UsersResponse
	{
		return $this->searcher->__invoke();
	}
}
