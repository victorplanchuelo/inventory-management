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
		Schema::create('inventory_stock_items', function (Blueprint $table) {
			$table->uuid('id')->primary();
			$table->uuid('product_id')->unique('inventory_stock_items_product_id_unique');
			$table->unsignedBigInteger('on_hand');
			$table->unsignedBigInteger('reserved')->default(0);

			// UTC wall-clock values. DATETIME avoids MySQL session-timezone conversions.
			$table->dateTime('created_at');
			$table->dateTime('updated_at');
		});

		DB::statement(
			'ALTER TABLE inventory_stock_items ADD CONSTRAINT inventory_stock_items_reserved_within_on_hand CHECK (reserved <= on_hand)'
		);
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('inventory_stock_items');
	}
};
