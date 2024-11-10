<?php

namespace Tests\Shared\Infrastructure\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

use function Lambdish\Phunctional\first;
use function Lambdish\Phunctional\map;

final class MySqlDatabaseCleaner
{
    public function __invoke(DatabaseManager $databaseManager): void
    {
        $connection        = $databaseManager->connection();
        $tables            = $this->tables($connection);
        $truncateTablesSql = $this->truncateDatabaseSql($tables);

        $connection->unprepared($truncateTablesSql);
    }

    private function truncateDatabaseSql(array $tables): string
    {
        $truncateTables = map($this->truncateTableSql(), $tables);

        return sprintf('SET FOREIGN_KEY_CHECKS = 0; %s SET FOREIGN_KEY_CHECKS = 1;', implode(' ', $truncateTables));
    }

    private function truncateTableSql(): callable
    {
        return fn(array $table): string => sprintf('TRUNCATE TABLE `%s`;', (string)first($table));
    }

    private function tables(Connection $connection): array
    {
        return $connection->select('SHOW TABLES');
    }
}
