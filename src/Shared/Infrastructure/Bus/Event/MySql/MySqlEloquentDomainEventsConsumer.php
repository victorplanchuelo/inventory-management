<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\MySql;

use DateMalformedStringException;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Domain\Utils;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use DateTimeImmutable;
use RuntimeException;

use function Lambdish\Phunctional\each;
use function Lambdish\Phunctional\map;

final readonly class MySqlEloquentDomainEventsConsumer
{
	public function __construct(private DomainEventMapping $eventMapping)
	{
	}

	public function consume(callable $subscribers, int $eventsToConsume): void
	{
        $events = DB::table('domain_events')
            ->orderBy('occurred_on')
            ->limit($eventsToConsume)
            ->get()
            ->map(function($event) {
                return (array) $event;
            })
            ->toArray();

        each($this->executeSubscribers($subscribers), $events);
        $ids = implode(', ', map($this->idExtractor(), $events));

        if (!empty($ids)) {
            DB::table('domain_events')
                ->whereIn('id', [$ids])
                ->delete();
		}
	}

	private function executeSubscribers(callable $subscribers): callable
	{
		return function (array $rawEvent) use ($subscribers): void {
			try {
				$domainEventClass = $this->eventMapping->for($rawEvent['name']);
				$domainEvent = $domainEventClass::fromPrimitives(
					$rawEvent['aggregate_id'],
					Utils::jsonDecode($rawEvent['body']),
					$rawEvent['id'],
					$this->formatDate($rawEvent['occurred_on'])
				);

				$subscribers($domainEvent);
			} catch (RuntimeException) {
			}
		};
	}

    /**
     * @throws DateMalformedStringException
     */
    private function formatDate(mixed $stringDate): string
	{
		return Utils::dateToString(new DateTimeImmutable($stringDate));
	}

	private function idExtractor(): callable
	{
		return static fn (array $event): string => "'{$event['id']}'";
	}
}
