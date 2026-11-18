<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\Exception\MissingAggregateVersion;

/**
 * Only use this policy when the subscription covers the complete aggregate sequence.
 *
 * A gap is not proof of a lost message for a partial subscription. When a consumer starts without a
 * baseline, the first observed version is accepted, so strict ordering across restarts requires a
 * context-owned initialization or reconciliation strategy.
 */
final readonly class StrictSequentialAggregateVersionPolicy implements AggregateVersionPolicy
{
	public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
	{
		if ($incomingVersion === null) {
			throw MissingAggregateVersion::becauseStrictOrderingRequiresIt();
		}

		if ($lastAppliedVersion === null) {
			return AggregateVersionDecision::PROCESS;
		}

		if ($incomingVersion <= $lastAppliedVersion) {
			return AggregateVersionDecision::STALE;
		}

		return $incomingVersion === $lastAppliedVersion + 1
			? AggregateVersionDecision::PROCESS
			: AggregateVersionDecision::GAP;
	}
}
