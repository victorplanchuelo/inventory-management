<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQueryHandler;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use Manager\Orders\Infrastructure\IntegrationEvent\InventoryStockProjectionSubscriber;
use Manager\Orders\Infrastructure\Persistence\Repositories\MySqlInventoryAvailabilityRepository;
use Manager\Shared\Infrastructure\Inbox\InboxConnectionFactory;

final class OrdersServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(
			MySqlInventoryAvailabilityRepository::class,
			static fn (Application $app): MySqlInventoryAvailabilityRepository => new MySqlInventoryAvailabilityRepository(
				$app->make(InboxConnectionFactory::class)->connection()
			)
		);

		$this->app->alias(MySqlInventoryAvailabilityRepository::class, InventoryAvailabilityRepository::class);

		$this->app->tag(InventoryStockProjectionSubscriber::class, 'integration_event_subscriber');
		$this->app->tag(GetInventoryAvailabilityQueryHandler::class, 'query_handler');
	}
}
