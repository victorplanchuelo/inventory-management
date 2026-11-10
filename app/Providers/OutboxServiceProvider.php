<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedPublisher;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRepository;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxEventPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use Manager\Shared\Infrastructure\Outbox\OutboxPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxRepository;

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

		$this->app->singleton(OutboxRepository::class, static function (Application $app): OutboxRepository {
			$leaseSeconds = config('outbox.lease_seconds', 300);

			return new MySqlOutboxRepository(
				$app->make(OutboxConnectionFactory::class)->connection(),
				is_numeric($leaseSeconds) ? (int) $leaseSeconds : 300
			);
		});

		$this->app->singleton(
			OutboxEnvelopeSerializer::class,
			static fn (): OutboxEnvelopeSerializer => new OutboxEnvelopeSerializer()
		);

		$this->app->singleton(OutboxEventPublisher::class, static function (): OutboxEventPublisher {
			$configuration = config('queue.connections.rabbitmq');
			$exchange = config('outbox.exchange');
			$timeout = config('outbox.confirm_timeout_seconds');

			/** @var array<string, mixed> $rabbitMqConfiguration */
			$rabbitMqConfiguration = is_array($configuration) ? $configuration : [];

			return new RabbitMqConfirmedPublisher(
				$rabbitMqConfiguration,
				is_string($exchange) && $exchange !== '' ? $exchange : 'integration_events',
				is_numeric($timeout) ? (float) $timeout : 5.0
			);
		});

		$this->app->singleton(OutboxPublisher::class, static function (Application $app): OutboxPublisher {
			return new OutboxPublisher(
				$app->make(OutboxRepository::class),
				$app->make(OutboxEnvelopeSerializer::class),
				$app->make(OutboxEventPublisher::class),
				sprintf('%s:%d', (string) gethostname(), (int) getmypid())
			);
		});
	}
}
