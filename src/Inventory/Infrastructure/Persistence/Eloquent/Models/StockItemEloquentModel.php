<?php

declare(strict_types=1);

namespace Manager\Inventory\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $product_id
 * @property int $on_hand
 * @property int $reserved
 * @property string $created_at
 * @property string $updated_at
 * @property-read Collection<int, StockReservationEloquentModel> $reservations
 */
final class StockItemEloquentModel extends Model
{
	protected $table = 'inventory_stock_items';
	protected $primaryKey = 'id';
	public $incrementing = false;
	protected $keyType = 'string';
	public $timestamps = false;

	/** @var list<string> */
	protected $fillable = ['id', 'product_id', 'on_hand', 'reserved', 'created_at', 'updated_at'];

	/**
	 * @return HasMany<StockReservationEloquentModel, $this>
	 */
	public function reservations(): HasMany
	{
		return $this->hasMany(StockReservationEloquentModel::class, 'stock_item_id', 'id')
			->orderBy('created_at')
			->orderBy('id');
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array
	{
		return [
			'on_hand' => 'integer',
			'reserved' => 'integer',
		];
	}
}
