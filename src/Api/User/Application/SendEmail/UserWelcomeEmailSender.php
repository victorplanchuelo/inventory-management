<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\SendEmail;

use Illuminate\Support\Facades\Mail;
use Manager\Api\User\Domain\UserRepository;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Shared\Domain\Bus\Event\EventBus;

final readonly class UserWelcomeEmailSender
{
	public function __construct(
		private UserRepository $repository,
	) {}

	public function __invoke(UserEmail $email): void
	{
		//$counter = $this->repository->search() ?: $this->initializeCounter();

		//TODO. Do something sending a welcome email or something
        dump('send email');
        Mail::raw('Hello World!', function($msg) {$msg->to('myemail@gmail.com')->subject('Test Email'); });


        //$this->bus->publish(...$counter->pullDomainEvents());
	}
}
