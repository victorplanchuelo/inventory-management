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
		Schema::create('inventory_stock_reservations', function (Blueprint $table) {
			$table->uuid('id')->primary();
			$table->uuid('stock_item_id');
			$table->uuid('order_id');
			$table->unsignedBigInteger('quantity');
			$table->string('status', 16);

			// UTC wall-clock values. DATETIME avoids MySQL session-timezone conversions.
			$table->dateTime('created_at');
			$table->dateTime('updated_at');

			$table->index('stock_item_id', 'inventory_stock_reservations_stock_item_id_index');
			$table->foreign('stock_item_id', 'inventory_stock_reservations_stock_item_id_foreign')
				->references('id')
				->on('inventory_stock_items')
				->restrictOnDelete();
		});

		DB::statement(
			'ALTER TABLE inventory_stock_reservations ADD CONSTRAINT inventory_stock_reservations_quantity_positive CHECK (quantity > 0)'
		);

		DB::statement(
			"ALTER TABLE inventory_stock_reservations ADD CONSTRAINT inventory_stock_reservations_status_valid CHECK (status IN ('active', 'released'))"
		);
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('inventory_stock_reservations');
	}
};
