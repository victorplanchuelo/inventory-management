<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Inbox;

use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\Inbox\InboxStoreFactory;

final readonly class FixedInboxStoreFactory implements InboxStoreFactory
{
	public function __construct(private InboxStore $store) {}

	public function forConsumer(string $consumer): InboxStore
	{
		return $this->store;
	}
}
