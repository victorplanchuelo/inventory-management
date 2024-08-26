<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event;

use Manager\Shared\Domain\Bus\Event\DomainEvent;
use RuntimeException;

final class EventNotRegisteredError extends RuntimeException
{
	public function __construct(DomainEvent $event)
	{
		$eventClass = $event::class;

		parent::__construct("The event <$eventClass> hasn't a event handler associated");
	}
}
