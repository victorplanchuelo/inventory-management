<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Support;

use Manager\Shared\Application\Transaction\TransactionalSession;

final class CountingTransactionalSession implements TransactionalSession
{
	public int $calls = 0;

	public function execute(callable $operation, int $attempts = 1): mixed
	{
		$this->calls++;

		return $operation();
	}
}
