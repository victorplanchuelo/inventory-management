<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Database;

use Illuminate\Database\Connection;
use Manager\Shared\Application\Transaction\TransactionalSession;

final readonly class MySqlTransactionalSession implements TransactionalSession
{
	public function __construct(private Connection $connection) {}

	public function execute(callable $operation): mixed
	{
		return $this->connection->transaction(static fn (): mixed => $operation());
	}
}
