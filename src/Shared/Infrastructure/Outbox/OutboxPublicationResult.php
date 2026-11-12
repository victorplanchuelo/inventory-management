<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

enum OutboxPublicationResult
{
	case PUBLISHED;
	case RETRY_SCHEDULED;
	case FAILED;
}
