<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Transaction;

interface TransactionalSession
{
	public function execute(callable $operation): mixed;
}
