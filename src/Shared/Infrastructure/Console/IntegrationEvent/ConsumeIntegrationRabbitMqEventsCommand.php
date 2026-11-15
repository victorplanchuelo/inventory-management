<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Console\IntegrationEvent;

use Illuminate\Console\Command;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventSubscriberLocator;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqIntegrationEventConsumer;
use Manager\Shared\Infrastructure\Database\DatabaseConnections;

final class ConsumeIntegrationRabbitMqEventsCommand extends Command
{
	protected $signature = 'manager:integration-events:rabbitmq:consume {queue} {quantity}';

	protected $description = 'Consume up to the requested number of integration events from RabbitMQ';

	public function __construct(
		private readonly RabbitMqIntegrationEventConsumer $consumer,
		private readonly DatabaseConnections $connections,
		private readonly IntegrationEventSubscriberLocator $subscriberLocator,
	) {
		parent::__construct();
	}

	public function handle(): int
	{
		$queueName = (string) $this->argument('queue');
		$rawQuantity = $this->argument('quantity');
		$quantityText = is_numeric($rawQuantity) ? trim((string) $rawQuantity) : '';

		if (preg_match('/^[1-9][0-9]*$/', $quantityText) !== 1) {
			$this->error('The quantity must be a positive integer.');

			return self::FAILURE;
		}

		$quantity = (int) $quantityText;
		$subscriber = $this->subscriberLocator->withQueueNamed($queueName);
		$processed = 0;

		while ($processed < $quantity) {
			if (!$this->consumer->consumeOne($subscriber, $queueName)) {
				break;
			}

			$processed++;
			$this->connections->clear();
		}

		$this->info(sprintf('Processed %d integration event(s).', $processed));

		return self::SUCCESS;
	}
}
