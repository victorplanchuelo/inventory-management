<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

enum InboxProcessingResult: string
{
	case PROCESSED = 'processed';
	case DUPLICATE = 'duplicate';
}
