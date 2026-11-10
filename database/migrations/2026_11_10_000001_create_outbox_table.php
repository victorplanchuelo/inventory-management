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
		Schema::create('outbox', function (Blueprint $table) {
			$table->uuid('id')->primary();
			$table->string('producer', 64);
			$table->uuid('event_id')->unique();
			$table->string('event_name');
			$table->string('event_version', 32);
			$table->string('aggregate_type');
			$table->uuid('aggregate_id');
			$table->unsignedBigInteger('aggregate_version')->nullable();
			$table->string('correlation_id')->nullable();
			$table->string('causation_id')->nullable();
			$table->longText('payload');

			// UTC wall-clock values. DATETIME avoids MySQL session-timezone conversions.
			$table->dateTime('occurred_at');
			$table->dateTime('created_at');
			$table->dateTime('published_at')->nullable();
			$table->unsignedInteger('attempts')->default(0);
			$table->dateTime('next_attempt_at')->nullable();
			$table->dateTime('locked_at')->nullable();
			$table->string('claim_token', 64)->nullable();
			$table->string('publisher_id', 128)->nullable();
			$table->text('last_error')->nullable();
			$table->string('status', 16)->default('pending');
			$table->dateTime('failed_at')->nullable();

			$table->index(['status', 'next_attempt_at']);
			$table->index(['status', 'locked_at']);
		});

		DB::statement('ALTER TABLE outbox ADD CONSTRAINT outbox_payload_is_valid_json CHECK (JSON_VALID(payload))');
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('outbox');
	}
};
