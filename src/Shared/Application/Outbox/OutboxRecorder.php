<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

interface OutboxRecorder
{
	public function append(OutboxMessage $message): void;
}
