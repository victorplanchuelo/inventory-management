<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox\Exception;

use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;

final class MissingAggregateVersion extends PermanentIntegrationEventFailure
{
	public static function becauseStrictOrderingRequiresIt(): self
	{
		return new self(
			'The strict sequential aggregate-version policy requires an aggregate version, but the event does not provide one.'
		);
	}
}
