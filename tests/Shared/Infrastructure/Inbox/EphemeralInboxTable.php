<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Inbox;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

final class EphemeralInboxTable
{
	private const CHECK_CONSTRAINT_SUFFIX = '_processing_state_is_consistent';

	public readonly string $inboxTable;
	public readonly string $businessTable;

	public function __construct(private readonly Connection $connection)
	{
		$suffix = bin2hex(random_bytes(6));

		$this->inboxTable = 'inbox_probe_' . $suffix;
		$this->businessTable = 'biz_probe_' . $suffix;
	}

	public function create(): void
	{
		$this->connection->getSchemaBuilder()->create($this->inboxTable, function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('consumer', 128);
			$table->uuid('event_id');
			$table->string('event_name');
			$table->string('event_version', 32);
			$table->string('aggregate_type');
			$table->string('aggregate_id');
			$table->unsignedBigInteger('aggregate_version')->nullable();
			$table->dateTime('occurred_at');
			$table->dateTime('processed_at')->nullable();
			$table->string('outcome', 16)->nullable();
			$table->string('correlation_id')->nullable();
			$table->string('causation_id')->nullable();
			$table->dateTime('created_at');

			$table->unique(['consumer', 'event_id'], $this->inboxTable . '_consumer_event_id_unique');
			$table->index(
				['consumer', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
				$this->inboxTable . '_consumer_aggregate_version_index'
			);
			$table->index(['consumer', 'processed_at'], $this->inboxTable . '_consumer_processed_at_index');
			$table->index(['consumer', 'outcome'], $this->inboxTable . '_consumer_outcome_index');
		});

		$this->connection->statement(sprintf(
			'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
			$this->inboxTable,
			$this->inboxTable . self::CHECK_CONSTRAINT_SUFFIX,
			'(processed_at IS NULL AND outcome IS NULL) OR (processed_at IS NOT NULL AND outcome IS NOT NULL)'
		));
	}

	public function createBusinessTable(): void
	{
		$this->connection->getSchemaBuilder()->create($this->businessTable, function (Blueprint $table): void {
			$table->uuid('id')->primary();
			$table->string('note');
		});
	}

	public function drop(): void
	{
		$this->connection->getSchemaBuilder()->dropIfExists($this->businessTable);
		$this->connection->getSchemaBuilder()->dropIfExists($this->inboxTable);
	}

	public function exists(string $table): bool
	{
		return $this->connection->table('information_schema.TABLES')
			->where('TABLE_SCHEMA', $this->connection->getDatabaseName())
			->where('TABLE_NAME', $table)
			->exists();
	}
}
