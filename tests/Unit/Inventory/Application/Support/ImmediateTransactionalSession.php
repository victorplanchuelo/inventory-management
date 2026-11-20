<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Support;

use Manager\Shared\Application\Transaction\TransactionalSession;

final readonly class ImmediateTransactionalSession implements TransactionalSession
{
	public function execute(callable $operation, int $attempts = 1): mixed
	{
		return $operation();
	}
}
