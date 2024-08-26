<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Manager\Shared\Domain\Bus\Command\CommandBus;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Domain\Bus\Query\QueryBus;
use Manager\Shared\Infrastructure\Bus\Command\InMemorySymfonyCommandBus;
use Manager\Shared\Infrastructure\Bus\Query\InMemorySymfonyQueryBus;

final class AppServiceProvider extends ServiceProvider
{
	/**
	 * Register any application services.
	 */
	public function register(): void
	{
		$this->app->bind(EventBus::class, function ($app) {
			return new ($app->tagged('domain_event_subscriber'));
		});

		$this->app->bind(
			QueryBus::class,
			function ($app) {
				return new InMemorySymfonyQueryBus($app->tagged('query_handler'));
			}
		);

		$this->app->bind(
			CommandBus::class,
			function ($app) {
				return new InMemorySymfonyCommandBus($app->tagged('command_handler'));
			}
		);

		//        $this->app->bind(
		//            UuidGeneratorInterface::class,
		//            RamseyUuidGenerator::class
		//        );
	}

	/**
	 * Bootstrap any application services.
	 */
	public function boot(): void
	{
		//
	}
}
