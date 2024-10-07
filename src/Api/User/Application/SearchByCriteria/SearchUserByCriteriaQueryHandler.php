<?php

namespace Manager\Api\User\Application\SearchByCriteria;

use Manager\Api\User\Application\UsersResponse;
use Manager\Shared\Domain\Criteria\Filters;
use Manager\Shared\Domain\Criteria\InvalidCriteriaException;
use Manager\Shared\Domain\Criteria\Order;

final readonly class SearchUserByCriteriaQueryHandler
{
    public function __construct(private UserByCriteriaSearcher $searcher) {}

    /**
     * @throws InvalidCriteriaException
     */
    public function __invoke(SearchUserByCriteriaQuery $query): UsersResponse
    {
        $filters = Filters::fromPrimitives($query->filters());
        $order = Order::fromPrimitives($query->orderBy(), $query->order());

        return $this->searcher->search($filters, $order, $query->limit(), $query->offset());
    }
}
