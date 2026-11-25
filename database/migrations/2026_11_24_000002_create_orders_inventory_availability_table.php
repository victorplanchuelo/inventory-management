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
		Schema::create('orders_inventory_availability', function (Blueprint $table) {
			$table->uuid('product_id')->primary();
			$table->uuid('stock_item_id');
			$table->unsignedBigInteger('on_hand');
			$table->unsignedBigInteger('reserved')->default(0);

			// UTC wall-clock values. DATETIME avoids MySQL session-timezone conversions.
			$table->dateTime('source_occurred_at');
			$table->dateTime('updated_at');

			$table->index('stock_item_id', 'orders_inventory_availability_stock_item_id_index');
		});

		DB::statement(
			'ALTER TABLE orders_inventory_availability ADD CONSTRAINT orders_inventory_availability_on_hand_non_negative CHECK (on_hand >= 0)'
		);

		DB::statement(
			'ALTER TABLE orders_inventory_availability ADD CONSTRAINT orders_inventory_availability_reserved_non_negative CHECK (reserved >= 0)'
		);

		DB::statement(
			'ALTER TABLE orders_inventory_availability ADD CONSTRAINT orders_inventory_availability_reserved_within_on_hand CHECK (reserved <= on_hand)'
		);
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('orders_inventory_availability');
	}
};
