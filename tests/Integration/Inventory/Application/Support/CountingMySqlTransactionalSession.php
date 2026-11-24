<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application\Support;

use Illuminate\Database\Connection;
use Manager\Shared\Application\Transaction\TransactionalSession;

final class CountingMySqlTransactionalSession implements TransactionalSession
{
	public int $invocations = 0;

	public function __construct(private readonly Connection $connection) {}

	public function execute(callable $operation, int $attempts = 1): mixed
	{
		return $this->connection->transaction(function () use ($operation): mixed {
			$this->invocations++;

			return $operation();
		}, $attempts);
	}
}
