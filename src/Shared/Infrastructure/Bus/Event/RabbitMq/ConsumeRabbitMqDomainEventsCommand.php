<?php

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;


use Illuminate\Console\Command;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventSubscriberLocator;

use Manager\Shared\Infrastructure\Database\DatabaseConnections;
use function Lambdish\Phunctional\pipe;
use function Lambdish\Phunctional\repeat;

final class ConsumeRabbitMqDomainEventsCommand extends Command
{
    public function __construct(
        private readonly RabbitMqDomainEventsConsumer $consumer,
        private readonly DatabaseConnections $connections,
        private readonly DomainEventSubscriberLocator $subscriberLocator
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager:domain-events:rabbitmq:consume {queue} {quantity}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume domain events from RabbitMq';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $queueName = (string) $this->argument('queue');
        $quantityEventsToProcess = (int) $this->argument('quantity');

        repeat($this->consumer($queueName), $quantityEventsToProcess);
    }

    private function consumer(string $queueName): callable
    {
        return function () use ($queueName): void {
            $subscriber = $this->subscriberLocator->withRabbitMqQueueNamed($queueName);

            $this->consumer->consume($subscriber, $queueName);

            $this->connections->clear();
        };
    }
}
