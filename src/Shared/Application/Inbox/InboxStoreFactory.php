<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

interface InboxStoreFactory
{
	public function forConsumer(string $consumer): InboxStore;
}
