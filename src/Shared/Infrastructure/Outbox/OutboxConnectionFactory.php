<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class OutboxConnectionFactory
{
	public function __construct(private DatabaseManager $databaseManager) {}

	/**
	 * The Outbox must always use the application's default connection.
	 *
	 * Allowing an independent Outbox connection would let business repositories
	 * and the Outbox writer open separate transactions, breaking atomicity.
	 */
	public function connection(): Connection
	{
		return $this->databaseManager->connection();
	}
}
