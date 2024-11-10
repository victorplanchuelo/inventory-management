<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Database;

use Illuminate\Support\Facades\DB;
use Manager\Shared\Domain\Utils;

use Tests\Shared\Infrastructure\Eloquent\MySqlDatabaseCleaner;
use function Lambdish\Phunctional\apply;
use function Lambdish\Phunctional\each;

final readonly class DatabaseConnections
{
	private array $connections;

	public function __construct(iterable $connections)
	{
		$this->connections = Utils::iterableToArray($connections);
	}

	public function clear(): void
	{
		each(fn () => DB::disconnect(), $this->connections);
	}

	public function truncate(): void
	{
		apply(new MySqlDatabaseCleaner(), array_values($this->connections));
	}
}
