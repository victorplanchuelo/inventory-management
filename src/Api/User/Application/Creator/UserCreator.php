<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Creator;

use Manager\Api\User\Application\SearchByCriteria\UserByCriteriaSearcher;
use Manager\Api\User\Domain\Exceptions\UserAlreadyExistsException;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserRepository;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Domain\Criteria\Filters;
use Manager\Shared\Domain\Criteria\InvalidCriteriaException;
use Manager\Shared\Domain\Criteria\Order;

final readonly class UserCreator
{
    private UserByCriteriaSearcher $finder;

    public function __construct(
        private UserRepository $repository,
        private EventBus       $bus,
    ) {
        $this->finder = new UserByCriteriaSearcher($repository);
    }

    /**
     * @throws InvalidCriteriaException
     */
    public function __invoke(string $uuid, string $name, string $email, string $password): void
    {
        $filters = Filters::fromPrimitives([
            [
                'field'    => 'email',
                'operator' => '=',
                'value'    => $email,
            ]
        ]);

        $userResponse = $this->finder->search(filters: $filters, order: Order::none(), limit: null, offset: null);
        if (!empty($userResponse->users())) {
            throw new UserAlreadyExistsException(new UserEmail($email));
        }

        $user      = User::create(uuid: $uuid, id: null, name: $name, email: $email, password: $password);
        $userSaved = $this->repository->save($user);

        $userSaved->pushDomainEvent();

        $this->bus->publish(...$userSaved->pullDomainEvents());
    }
}
