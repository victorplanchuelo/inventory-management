<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Inbox;

use Illuminate\Database\Connection;
use Manager\Shared\Application\Inbox\Exception\InvalidInboxConsumer;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\Inbox\InboxStoreFactory;

final readonly class MySqlInboxStoreFactory implements InboxStoreFactory
{
	/**
	 * @param array<string, string> $tables
	 */
	public function __construct(
		private Connection $connection,
		private array $tables,
	) {}

	public function forConsumer(string $consumer): InboxStore
	{
		$context = explode('.', $consumer, 2)[0];
		$table = $this->tables[$context] ?? null;

		if ($table === null || trim($table) === '') {
			throw InvalidInboxConsumer::becauseContextIsNotConfigured($consumer);
		}

		return new MySqlInboxStore($this->connection, $table);
	}
}
