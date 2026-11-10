<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

interface OutboxEventPublisher
{
	public function publish(OutboxRecord $record, string $envelope): void;
}
