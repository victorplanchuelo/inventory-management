<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use InvalidArgumentException;

final class UnknownOutboxProducerException extends InvalidArgumentException
{
	/** @param list<string> $registeredProducers */
	public static function named(string $producer, array $registeredProducers): self
	{
		return new self(
			sprintf(
				'Unknown Outbox producer <%s>. Registered producers: <%s>.',
				$producer,
				implode(', ', $registeredProducers)
			)
		);
	}
}
