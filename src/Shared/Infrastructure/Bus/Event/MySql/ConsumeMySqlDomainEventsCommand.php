<?php

namespace Manager\Shared\Infrastructure\Bus\Event\MySql;


use Illuminate\Console\Command;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventSubscriberLocator;

use Manager\Shared\Infrastructure\Database\DatabaseConnections;
use function Lambdish\Phunctional\pipe;

final class ConsumeMySqlDomainEventsCommand extends Command
{
    public function __construct(
        private readonly MySqlEloquentDomainEventsConsumer $consumer,
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
    protected $signature = 'manager:domain-events:mysql:consume {quantity}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume domain events from MySql';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $quantityEventsToProcess = (int) $this->argument('quantity');

        $consumer = pipe($this->consumer(), fn () => $this->connections->clear());
        $this->consumer->consume($consumer, $quantityEventsToProcess);
    }

    private function consumer(): callable
    {
        return function (DomainEvent $domainEvent): void {
            $subscribers = $this->subscriberLocator->allSubscribedTo($domainEvent::class);

            foreach ($subscribers as $subscriber) {
                $subscriber($domainEvent);
            }
        };
    }
}
