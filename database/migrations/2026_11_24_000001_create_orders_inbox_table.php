<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::create('orders_inbox', function (Blueprint $table) {
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

			$table->unique(['consumer', 'event_id'], 'orders_inbox_consumer_event_id_unique');
			$table->index(
				['consumer', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
				'orders_inbox_consumer_aggregate_version_index'
			);
			$table->index(['consumer', 'processed_at'], 'orders_inbox_consumer_processed_at_index');
			$table->index(['consumer', 'outcome'], 'orders_inbox_consumer_outcome_index');
		});

		DB::statement(
			'ALTER TABLE orders_inbox ADD CONSTRAINT orders_inbox_processing_state_is_consistent '
			. 'CHECK ((processed_at IS NULL AND outcome IS NULL) '
			. 'OR (processed_at IS NOT NULL AND outcome IS NOT NULL))'
		);
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('orders_inbox');
	}
};
