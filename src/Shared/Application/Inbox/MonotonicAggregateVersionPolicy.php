<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

/**
 * Recommended default for projections. Missing versions process without invented ordering.
 */
final readonly class MonotonicAggregateVersionPolicy implements AggregateVersionPolicy
{
	public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
	{
		if ($incomingVersion === null || $lastAppliedVersion === null) {
			return AggregateVersionDecision::PROCESS;
		}

		return $incomingVersion > $lastAppliedVersion
			? AggregateVersionDecision::PROCESS
			: AggregateVersionDecision::STALE;
	}
}
