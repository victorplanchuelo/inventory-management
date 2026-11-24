<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Manager\Inventory\Application\AddStock\AddStockCommandHandler;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\RegisterInitialStock\RegisterInitialStockCommandHandler;
use Manager\Inventory\Application\ReleaseStock\ReleaseStockCommandHandler;
use Manager\Inventory\Application\ReserveStock\ReserveStockCommandHandler;
use Manager\Inventory\Application\Stock\StockItemExclusiveReader;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Inventory\Domain\StockItemRepository;
use Manager\Inventory\Infrastructure\Persistence\Mappers\StockItemPersistenceMapper;
use Manager\Inventory\Infrastructure\Persistence\Repositories\MySqlStockItemRepository;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;

final class InventoryServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(
			StockItemPersistenceMapper::class,
			static fn (): StockItemPersistenceMapper => new StockItemPersistenceMapper()
		);

		$this->app->singleton(
			StockItemIntegrationEventMapper::class,
			static fn (): StockItemIntegrationEventMapper => new StockItemIntegrationEventMapper()
		);

		$this->app->singleton(
			StockItemIntegrationEventRecorder::class,
			static fn (Application $app): StockItemIntegrationEventRecorder => new StockItemIntegrationEventRecorder(
				$app->make(OutboxRecorder::class),
				$app->make(StockItemIntegrationEventMapper::class)
			)
		);

		$this->app->singleton(
			StockItemTransaction::class,
			static fn (Application $app): StockItemTransaction => new StockItemTransaction(
				$app->make(TransactionalSession::class),
				$app->make(StockItemIntegrationEventRecorder::class)
			)
		);

		$this->app->singleton(MySqlStockItemRepository::class, static function (Application $app): MySqlStockItemRepository {
			return new MySqlStockItemRepository(
				$app->make(OutboxConnectionFactory::class)->connection(),
				$app->make(StockItemPersistenceMapper::class)
			);
		});

		$this->app->alias(MySqlStockItemRepository::class, StockItemRepository::class);
		$this->app->alias(MySqlStockItemRepository::class, StockItemExclusiveReader::class);

		$this->app->tag(
			[
				RegisterInitialStockCommandHandler::class,
				AddStockCommandHandler::class,
				ReserveStockCommandHandler::class,
				ReleaseStockCommandHandler::class,
			],
			'command_handler'
		);
	}
}
