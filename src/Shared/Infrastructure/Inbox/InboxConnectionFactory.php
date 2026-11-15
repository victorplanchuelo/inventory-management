<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class InboxConnectionFactory
{
	public function __construct(private DatabaseManager $databaseManager) {}

	/**
	 * The Inbox must always use the application's default connection.
	 *
	 * Allowing an independent Inbox connection would let the subscriber write
	 * on a different connection and break the shared transaction.
	 */
	public function connection(): Connection
	{
		return $this->databaseManager->connection();
	}
}
