<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Database;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Psr\Log\LoggerInterface;

final readonly class MySqlTransactionalSession implements TransactionalSession
{
	public function __construct(
		private Connection $connection,
		private ?LoggerInterface $logger = null
	) {}

	public function execute(callable $operation, int $attempts = 1): mixed
	{
		if ($attempts < 1) {
			throw new InvalidArgumentException(sprintf(
				'The transaction attempts must be at least one, <%d> given.',
				$attempts
			));
		}

		$invocation = 0;

		return $this->connection->transaction(function () use ($operation, $attempts, &$invocation): mixed {
			$invocation++;

			if ($invocation > 1) {
				$this->logger?->warning('Database transaction retry attempt.', [
					'attempt' => $invocation,
					'maxAttempts' => $attempts,
				]);
			}

			return $operation();
		}, $attempts);
	}
}
