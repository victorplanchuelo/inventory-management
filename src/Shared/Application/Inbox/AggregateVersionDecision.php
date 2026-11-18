<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

enum AggregateVersionDecision: string
{
	case PROCESS = 'process';
	case STALE = 'stale';
	case GAP = 'gap';
}
