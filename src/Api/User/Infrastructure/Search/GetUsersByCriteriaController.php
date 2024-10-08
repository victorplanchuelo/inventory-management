<?php

declare(strict_types=1);

namespace Manager\Api\User\Infrastructure\Search;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Manager\Api\User\Application\SearchByCriteria\SearchUserByCriteriaQuery;
use Manager\Api\User\Application\Searcher\GetAllUsersQuery;
use Manager\Api\User\Application\UserResponse;
use Manager\Shared\ApiBaseController;
use Manager\Shared\Domain\Bus\Query\QueryBus;
use function Lambdish\Phunctional\map;

final class GetUsersByCriteriaController extends ApiBaseController
{
	public function __construct(protected readonly QueryBus $bus) {}

	public function __invoke(Request $request): JsonResponse
	{
        $orderBy = $request->query->get('order_by');
        $order = $request->query->get('order');
        $limit = $request->query->get('limit');
        $offset = $request->query->get('offset');

		$response = $this->bus->ask(new SearchUserByCriteriaQuery(
            (array) $request->query->get('filters'),
            $orderBy ?? null,
            $order ?? null,
            $limit === null ? null : (int) $limit,
            $offset === null ? null : (int) $offset
        ));

		return new JsonResponse(
			map(
				fn (UserResponse $user): array => [
					'uuid' => $user->uuid(),
					'id' => $user->id(),
					'name' => $user->name(),
					'email' => $user->email(),
				],
				$response->users()
			),
			200,
			['Access-Control-Allow-Origin' => '*']
		);
	}
}
