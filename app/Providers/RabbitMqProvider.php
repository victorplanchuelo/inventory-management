<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\ConfigureRabbitMqCommand;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqDomainEventsConsumer;

class RabbitMqProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
//        dd($app['config']->get('queue.connections.rabbitmq'));
//
//        $this->app->singleton(RabbitMqConnection::class, function () {
//            return new RabbitMqConnection(config('queue.connections.rabbitmq'));
//        });
//
//        $this->app->singleton(DomainEventJsonDeserializer::class, function ($app) {
//            return new DomainEventJsonDeserializer($app->make(DomainEventMapping::class));
//        });
//
//        $this->app->singleton(RabbitMqConfigurer::class, function ($app) {
//            return new RabbitMqConfigurer($app->make(RabbitMqConnection::class));
//        });
//
//        $this->app->singleton(RabbitMqDomainEventsConsumer::class, function ($app) {
//            return new RabbitMqDomainEventsConsumer(
//                $app->make(RabbitMqConnection::class),
//                $app->make(DomainEventJsonDeserializer::class),
//                config('app.rabbitmq.RABBITMQ_EXCHANGE'),
//                config('app.rabbitmq.RABBITMQ_MAX_RETRIES', 5)
//            );
//        });
//
//        $this->app->singleton(ConfigureRabbitMqCommand::class, function ($app) {
//            return new ConfigureRabbitMqCommand(
//                $app->make(RabbitMqConfigurer::class),
//                config('app.rabbitmq.RABBITMQ_EXCHANGE'),
//                $app->tagged('domain_event_subscriber')
//            );
//        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
