<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\SearchByCriteria;

use Manager\Api\User\Application\UserResponse;
use Manager\Api\User\Application\UsersResponse;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserRepository;
use Manager\Shared\Domain\Criteria\Criteria;
use Manager\Shared\Domain\Criteria\Filters;
use Manager\Shared\Domain\Criteria\InvalidCriteriaException;
use Manager\Shared\Domain\Criteria\Order;
use function Lambdish\Phunctional\map;

final readonly class UserByCriteriaSearcher
{
	public function __construct(private UserRepository $repository) {}

    /**
     * @throws InvalidCriteriaException
     */
    public function search(Filters $filters, Order $order, ?int $limit, ?int $offset): UsersResponse
	{
        $criteria = new Criteria($filters, $order, $limit, $offset);
		return new UsersResponse(...map($this->toResponse(), $this->repository->matching($criteria)));
	}

	private function toResponse(): callable
	{
		return static fn (User $user): UserResponse => new UserResponse(
			$user->id()->value(),
			$user->uuid()->value(),
			$user->name()->value(),
			$user->email()->value(),
			$user->password()->value()
		);
	}
}
