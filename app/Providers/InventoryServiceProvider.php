<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;

final class InventoryServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(
			StockItemPersistenceMapper::class,
			static fn (): StockItemPersistenceMapper => new StockItemPersistenceMapper()
		);

		$this->app->singleton(MySqlStockItemRepository::class, static function (Application $app): MySqlStockItemRepository {
			return new MySqlStockItemRepository(
				$app->make(OutboxConnectionFactory::class)->connection(),
				$app->make(StockItemPersistenceMapper::class)
			);
		});

		$this->app->alias(MySqlStockItemRepository::class, StockItemRepository::class);
		$this->app->alias(MySqlStockItemRepository::class, StockItemExclusiveReader::class);
	}
}
