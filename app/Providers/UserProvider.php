<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Manager\Application\User\Commands\PostCreateUserCommandHandler;
use Manager\Application\User\Queries\GetAllUsersQueryHandler;
use Manager\Domain\User\UserRepository;
use Manager\Infrastructure\User\MySqlUserRepository;

final class UserProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->bind(UserRepository::class, MySqlUserRepository::class);

		$this->app->tag(PostCreateUserCommandHandler::class, 'command_handler');
		//
		//        $this->app->tag(
		//            DeleteBoardByIdCommandHandler::class,
		//            'command_handler'
		//        );

		$this->app->tag(GetAllUsersQueryHandler::class, 'query_handler');

		//        $this->app->tag(
		//            UpdateBoardCommandHandler::class,
		//            'command_handler'
		//        );
		//
		//        $this->app->tag(
		//            SomethingWithCreatedBoardSubscriber::class,
		//            'domain_event_subscriber'
		//        );
	}
}
