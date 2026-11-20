<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Support;

use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;

final class CollectingOutboxRecorder implements OutboxRecorder
{
	/** @var list<OutboxMessage> */
	private array $messages = [];

	public function append(OutboxMessage $message): void
	{
		$this->messages[] = $message;
	}

	/**
	 * @return list<OutboxMessage>
	 */
	public function messages(): array
	{
		return $this->messages;
	}
}
