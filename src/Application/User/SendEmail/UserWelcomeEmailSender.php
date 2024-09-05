<?php

declare(strict_types=1);

namespace Manager\Application\User\SendEmail;

use Manager\Domain\User\UserRepository;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Shared\Domain\Bus\Event\EventBus;

final readonly class UserWelcomeEmailSender
{
	public function __construct(
		private UserRepository $repository,
		private EventBus $bus
	) {}

	public function __invoke(UserEmail $email): void
	{
		//$counter = $this->repository->search() ?: $this->initializeCounter();

        //TODO. Do something sending a welcome email or something

        //$this->bus->publish(...$counter->pullDomainEvents());

	}
}
