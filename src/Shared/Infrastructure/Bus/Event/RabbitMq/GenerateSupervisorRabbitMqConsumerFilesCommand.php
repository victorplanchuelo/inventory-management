<?php

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;


use Illuminate\Console\Command;
use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventSubscriberLocator;

use function Lambdish\Phunctional\each;

final class GenerateSupervisorRabbitMqConsumerFilesCommand extends Command
{
    private const int EVENTS_TO_PROCESS_AT_TIME = 200;
    private const int NUMBERS_OF_PROCESSES_PER_SUBSCRIBER = 1;
    private const string SUPERVISOR_PATH = __DIR__ . '/../../../../build/supervisor';

    public function __construct(private readonly DomainEventSubscriberLocator $locator)
    {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager:domain-events:rabbitmq:generate-supervisor-files {path=/var/www/html}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the supervisor configuration for every RabbitMQ subscriber';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $path = (string) $this->argument('path');
        each($this->configCreator($path), $this->locator->all());
    }

    private function configCreator(string $path): callable
    {
        return function (DomainEventSubscriber $subscriber) use ($path): void {
            $queueName = RabbitMqQueueNameFormatter::format($subscriber);
            $subscriberName = RabbitMqQueueNameFormatter::shortFormat($subscriber);

            $fileContent = str_replace(
                ['{subscriber_name}', '{queue_name}', '{path}', '{processes}', '{events_to_process}', ],
                [
                    $subscriberName,
                    $queueName,
                    $path,
                    self::NUMBERS_OF_PROCESSES_PER_SUBSCRIBER,
                    self::EVENTS_TO_PROCESS_AT_TIME,
                ],
                $this->template()
            );

            file_put_contents($this->fileName($subscriberName), $fileContent);
        };
    }

    private function template(): string
    {
        return <<<EOF
            [program:codelytv_{queue_name}]
            command      = {path}/apps/mooc/backend/bin/console codely:domain-events:rabbitmq:consume --env=prod {queue_name} {events_to_process}
            process_name = %(program_name)s_%(process_num)02d
            numprocs     = {processes}
            startsecs    = 1
            startretries = 10
            exitcodes    = 2
            stopwaitsecs = 300
            autostart    = true
            EOF;
    }

    private function fileName(string $queue): string
    {
        return sprintf('%s/%s.ini', self::SUPERVISOR_PATH, $queue);
    }
}
