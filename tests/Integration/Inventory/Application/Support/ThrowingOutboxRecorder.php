<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application\Support;

use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use RuntimeException;

final readonly class ThrowingOutboxRecorder implements OutboxRecorder
{
	public function append(OutboxMessage $message): void
	{
		throw new RuntimeException('Outbox write failed.');
	}
}
