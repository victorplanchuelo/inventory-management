<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Manager\Shared\Domain\Bus\Command\CommandBus;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Domain\Bus\Query\QueryBus;
use Manager\Shared\Infrastructure\Bus\Command\InMemorySymfonyCommandBus;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventSubscriberLocator;
use Manager\Shared\Infrastructure\Bus\Event\InMemory\InMemorySymfonyEventBus;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBus;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\ConfigureRabbitMqCommand;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqDomainEventsConsumer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqEventBus;
use Manager\Shared\Infrastructure\Bus\Query\InMemorySymfonyQueryBus;
use Manager\Shared\Infrastructure\Database\DatabaseConnections;

final class AppServiceProvider extends ServiceProvider
{
	/**
	 * Register any application services.
	 */
	public function register(): void
	{
        $this->app->singleton(DatabaseConnections::class, function ($app) {
            return new DatabaseConnections(
                config('database.connections')
            );
        });

        $this->app->singleton(RabbitMqConnection::class, function () {
            return new RabbitMqConnection(config('queue.connections.rabbitmq'));
        });

         $this->app->singleton(DomainEventSubscriberLocator::class, function ($app) {
             return new DomainEventSubscriberLocator(
                 $app->tagged('domain_event_subscriber')
             );
         });

//
//        $this->app->singleton(DomainEventJsonDeserializer::class, function ($app) {
//            return new DomainEventJsonDeserializer($app->make(DomainEventMapping::class));
//        });
//
        $this->app->singleton(RabbitMqConfigurer::class, function ($app) {
            return new RabbitMqConfigurer($app->make(RabbitMqConnection::class));
        });

        $this->app->singleton(RabbitMqDomainEventsConsumer::class, function ($app) {
            return new RabbitMqDomainEventsConsumer(
                $app->make(RabbitMqConnection::class),
                $app->make(DomainEventJsonDeserializer::class),
                config('app.rabbitmq.RABBITMQ_EXCHANGE'),
                config('app.rabbitmq.RABBITMQ_MAX_RETRIES', 5)
            );
        });

        $this->app->singleton(ConfigureRabbitMqCommand::class, function ($app) {
            return new ConfigureRabbitMqCommand(
                $app->make(RabbitMqConfigurer::class),
                config('app.rabbitmq.RABBITMQ_EXCHANGE'),
                $app->tagged('domain_event_subscriber')
            );
        });


        $this->app->singleton(DomainEventMapping::class, function ($app) {
            return new DomainEventMapping($app->tagged('domain_event_subscriber'));
        });

		$this->app->bind(EventBus::class, function ($app) {
			//return new InMemorySymfonyEventBus($app->tagged('domain_event_subscriber'));
            //return new MySqlEloquentEventBus();

            return new RabbitMqEventBus(
                $app->make(RabbitMqConnection::class),
                config('app.rabbitmq.RABBITMQ_EXCHANGE'),
                new MySqlEloquentEventBus()
            );
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
