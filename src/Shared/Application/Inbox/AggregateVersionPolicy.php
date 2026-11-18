<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

/**
 * Decides whether an event must be processed, ignored as stale or retried because a predecessor is missing.
 *
 * The policy compares versions for one aggregate. It does not serialize different events and does not
 * create ordering guarantees by itself: duplicate suppression only covers (consumer, eventId). The default
 * deployment runs one active consumer per queue. Strict ordering with concurrent workers requires a
 * context-owned per-aggregate mechanism.
 */
interface AggregateVersionPolicy
{
	public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision;
}
