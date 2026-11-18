<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

/**
 * Processes every event without consulting aggregateVersion.
 */
final readonly class NoOrderingAggregateVersionPolicy implements AggregateVersionPolicy
{
	public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
	{
		return AggregateVersionDecision::PROCESS;
	}
}
