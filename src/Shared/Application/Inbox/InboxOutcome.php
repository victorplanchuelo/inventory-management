<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

enum InboxOutcome: string
{
	case PROCESSED = 'processed';
	case STALE = 'stale';
}
