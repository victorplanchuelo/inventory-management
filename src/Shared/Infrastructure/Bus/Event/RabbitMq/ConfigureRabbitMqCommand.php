<?php

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use Illuminate\Console\Command;
use Traversable;

final class ConfigureRabbitMqCommand extends Command
{
    public function __construct(
        private readonly RabbitMqConfigurer $configurer,
        private readonly string $exchangeName,
        private readonly Traversable $subscribers,
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager:domain-events:rabbitmq:configure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure the RabbitMQ to allow publish & consume domain events';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->configurer->configure($this->exchangeName, ...iterator_to_array($this->subscribers));
    }
}
