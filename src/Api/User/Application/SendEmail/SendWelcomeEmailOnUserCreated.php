<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\SendEmail;

use Manager\Api\User\Domain\UserCreatedDomainEvent;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;
use function Lambdish\Phunctional\apply;

final readonly class SendWelcomeEmailOnUserCreated implements DomainEventSubscriber
{
	public function __construct(private UserWelcomeEmailSender $sender) {}

	public static function subscribedTo(): array
	{
		return [UserCreatedDomainEvent::class];
	}

	public function __invoke(UserCreatedDomainEvent $event): void
	{
		$userEmail = new UserEmail($event->email());
		apply($this->sender, [$userEmail]);
	}
}
