<?php

declare(strict_types=1);

namespace Manager\Inventory\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $stock_item_id
 * @property string $order_id
 * @property int $quantity
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @property-read StockItemEloquentModel $stockItem
 */
final class StockReservationEloquentModel extends Model
{
	protected $table = 'inventory_stock_reservations';
	protected $primaryKey = 'id';
	public $incrementing = false;
	protected $keyType = 'string';
	public $timestamps = false;

	/** @var list<string> */
	protected $fillable = ['id', 'stock_item_id', 'order_id', 'quantity', 'status', 'created_at', 'updated_at'];

	/**
	 * @return BelongsTo<StockItemEloquentModel, $this>
	 */
	public function stockItem(): BelongsTo
	{
		return $this->belongsTo(StockItemEloquentModel::class, 'stock_item_id', 'id');
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array
	{
		return [
			'quantity' => 'integer',
		];
	}
}
