<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\MySql;

use Illuminate\Support\Facades\DB;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Domain\Utils;

use function Lambdish\Phunctional\each;

final class MySqlEloquentEventBus implements EventBus
{
	private const DATABASE_TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

	public function publish(DomainEvent ...$events): void
	{
		each($this->publisher(), $events);
	}

	private function publisher(): callable
	{
		return function (DomainEvent $domainEvent): void {
            $data = [
                'id' => $domainEvent->eventId(),
                'aggregate_id' => $domainEvent->aggregateId(),
                'name' => $domainEvent::eventName(),
                'body' => Utils::jsonEncode($domainEvent->toPrimitives()),
                'occurred_on' => Utils::stringToDate($domainEvent->occurredOn())->format(self::DATABASE_TIMESTAMP_FORMAT),
            ];

            DB::table('domain_events')->insert($data);
		};
	}
}
