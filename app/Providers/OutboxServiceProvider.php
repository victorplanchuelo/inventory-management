<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;

final class OutboxServiceProvider extends ServiceProvider
{
	/**
	 * Register any application services.
	 */
	public function register(): void
	{
		$this->app->singleton(OutboxConnectionFactory::class, static function (Application $app): OutboxConnectionFactory {
			/** @var DatabaseManager $databaseManager */
			$databaseManager = $app->make('db');

			return new OutboxConnectionFactory($databaseManager);
		});

		$this->app->bind(TransactionalSession::class, static function (Application $app): TransactionalSession {
			return new MySqlTransactionalSession($app->make(OutboxConnectionFactory::class)->connection());
		});

		$this->app->bind(OutboxRecorder::class, static function (Application $app): OutboxRecorder {
			$producers = config('outbox.producers');

			return new MySqlOutboxRecorder(
				$app->make(OutboxConnectionFactory::class)->connection(),
				new OutboxProducerValidator(is_array($producers) ? array_values(array_filter($producers, 'is_string')) : [])
			);
		});
	}
}
