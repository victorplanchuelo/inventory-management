<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\IntegrationEvent;

use Illuminate\Console\Command;
use Manager\Shared\Application\Inbox\Exception\IncompatibleInboxExchange;
use Manager\Shared\Application\Inbox\InboxExchangeValidator;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;

final class ConfigureIntegrationRabbitMqCommand extends Command
{
	protected $signature = 'manager:integration-events:rabbitmq:configure';

	protected $description = 'Configure the RabbitMQ topology for integration events';

	public function __construct(
		private readonly IntegrationEventRabbitMqConfigurer $configurer,
		private readonly InboxExchangeValidator $exchangeValidator,
		private readonly iterable $subscribers,
	) {
		parent::__construct();
	}

	public function handle(): int
	{
		try {
			$this->exchangeValidator->assertCompatible();
		} catch (IncompatibleInboxExchange $error) {
			$this->error($error->getMessage());

			return self::FAILURE;
		}

		$subscribers = is_array($this->subscribers)
			? $this->subscribers
			: iterator_to_array($this->subscribers, false);

		$this->configurer->configure(...$subscribers);
		$this->info('The integration-event RabbitMQ topology has been configured.');

		return self::SUCCESS;
	}
}
