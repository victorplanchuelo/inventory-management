<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxExchangeValidator;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\InboxStoreFactory;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventEnvelopeDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventFailurePublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventSubscriberLocator;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedIntegrationEventPublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqIntegrationEventConsumer;
use Manager\Shared\Infrastructure\Console\IntegrationEvent\ConfigureIntegrationRabbitMqCommand;
use Manager\Shared\Infrastructure\Database\MySqlTransactionalSession;
use Manager\Shared\Infrastructure\Inbox\InboxConnectionFactory;
use Manager\Shared\Infrastructure\Inbox\InboxLogger;
use Manager\Shared\Infrastructure\Inbox\MySqlInboxStoreFactory;
use Psr\Log\LoggerInterface;

final class InboxServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		// Keep the tag registered even when no subscriber exists yet: tagged()
		// returns an array for unknown tags, while the locator and the configure
		// command expect an iterable.
		$this->app->tag([], 'integration_event_subscriber');

		$this->app->singleton(InboxConnectionFactory::class, static function (Application $app): InboxConnectionFactory {
			/** @var DatabaseManager $databaseManager */
			$databaseManager = $app->make('db');

			return new InboxConnectionFactory($databaseManager);
		});

		$this->app->bind(TransactionalSession::class, static function (Application $app): TransactionalSession {
			return new MySqlTransactionalSession($app->make(InboxConnectionFactory::class)->connection());
		});

		$this->app->bind(
			InboxConsumerValidator::class,
			fn (): InboxConsumerValidator => new InboxConsumerValidator($this->contexts())
		);

		$this->app->bind(
			InboxExchangeValidator::class,
			fn (): InboxExchangeValidator => new InboxExchangeValidator($this->inboxExchange(), $this->outboxExchange())
		);

		$this->app->bind(InboxStoreFactory::class, function (Application $app): InboxStoreFactory {
			return new MySqlInboxStoreFactory($app->make(InboxConnectionFactory::class)->connection(), $this->contexts());
		});

		$this->app->bind(InboxProcessor::class, static function (Application $app): InboxProcessor {
			return new InboxProcessor(
				$app->make(TransactionalSession::class),
				$app->make(InboxStoreFactory::class),
				$app->make(InboxConsumerValidator::class),
			);
		});

		$this->app->singleton(IntegrationEventQueueNameFormatter::class);
		$this->app->singleton(IntegrationEventEnvelopeDeserializer::class);

		$this->app->singleton(
			IntegrationEventSubscriberLocator::class,
			static fn (Application $app): IntegrationEventSubscriberLocator => new IntegrationEventSubscriberLocator(
				$app->tagged('integration_event_subscriber')
			)
		);

		$this->app->singleton(InboxLogger::class, static function (Application $app): InboxLogger {
			$configuration = config('inbox.logging');
			$enabled = true;

			if (is_array($configuration) && array_key_exists('enabled', $configuration)) {
				$parsed = filter_var($configuration['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				$enabled = is_bool($parsed) ? $parsed : true;
			}

			return new InboxLogger($app->make(LoggerInterface::class), $enabled);
		});

		$this->app->singleton(
			IntegrationEventRabbitMqConfigurer::class,
			fn (Application $app): IntegrationEventRabbitMqConfigurer => new IntegrationEventRabbitMqConfigurer(
				$app->make(RabbitMqConnection::class),
				$this->inboxExchange()
			)
		);

		$this->app->singleton(
			IntegrationEventFailurePublisher::class,
			static function (): IntegrationEventFailurePublisher {
				$configuration = config('queue.connections.rabbitmq');
				$timeout = config('inbox.confirm_timeout_seconds');

				/** @var array<string, mixed> $rabbitMqConfiguration */
				$rabbitMqConfiguration = is_array($configuration) ? $configuration : [];

				return new RabbitMqConfirmedIntegrationEventPublisher(
					$rabbitMqConfiguration,
					is_numeric($timeout) ? (float) $timeout : 5.0
				);
			}
		);

		$this->app->singleton(
			RabbitMqIntegrationEventConsumer::class,
			fn (Application $app): RabbitMqIntegrationEventConsumer => new RabbitMqIntegrationEventConsumer(
				$app->make(RabbitMqConnection::class),
				$app->make(IntegrationEventEnvelopeDeserializer::class),
				$app->make(InboxProcessor::class),
				$app->make(InboxLogger::class),
				$app->make(IntegrationEventFailurePublisher::class),
				$this->inboxExchange(),
				$this->maxRetries()
			)
		);

		$this->app->singleton(
			ConfigureIntegrationRabbitMqCommand::class,
			static fn (Application $app): ConfigureIntegrationRabbitMqCommand => new ConfigureIntegrationRabbitMqCommand(
				$app->make(IntegrationEventRabbitMqConfigurer::class),
				$app->make(InboxExchangeValidator::class),
				$app->tagged('integration_event_subscriber')
			)
		);
	}

	public function boot(): void
	{
		$this->app->make(InboxExchangeValidator::class)->assertCompatible();
	}

	/**
	 * @return array<string, string>
	 */
	private function contexts(): array
	{
		$configured = config('inbox.contexts');

		if (!is_array($configured)) {
			return [];
		}

		$contexts = [];

		foreach ($configured as $context => $table) {
			if (is_string($context) && is_string($table) && trim($table) !== '') {
				$contexts[$context] = $table;
			}
		}

		return $contexts;
	}

	private function inboxExchange(): string
	{
		$exchange = config('inbox.exchange');

		return is_string($exchange) ? $exchange : '';
	}

	private function outboxExchange(): string
	{
		$exchange = config('outbox.exchange');

		return is_string($exchange) ? $exchange : '';
	}

	private function maxRetries(): int
	{
		$value = config('inbox.max_retries', 5);

		return is_numeric($value) ? max(0, (int) $value) : 5;
	}
}
