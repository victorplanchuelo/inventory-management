<?php

declare(strict_types=1);

namespace Manager\Orders\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use LogicException;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailability;
use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;

final readonly class MySqlInventoryAvailabilityRepository implements InventoryAvailabilityRepository
{
	private const TABLE = 'orders_inventory_availability';
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct(private Connection $connection) {}

	public function findByProductId(string $productId): ?InventoryAvailability
	{
		$row = $this->connection->table(self::TABLE)
			->where('product_id', $productId)
			->first();

		if ($row === null) {
			return null;
		}

		return new InventoryAvailability(
			(string) $row->product_id,
			(string) $row->stock_item_id,
			(int) $row->on_hand,
			(int) $row->reserved,
			$this->toDateTime((string) $row->source_occurred_at),
			$this->toDateTime((string) $row->updated_at),
		);
	}

	public function apply(InventoryAvailability $availability): void
	{
		$this->ensureTransactionIsActive();

		$this->connection->table(self::TABLE)->upsert(
			[[
				'product_id' => $availability->productId(),
				'stock_item_id' => $availability->stockItemId(),
				'on_hand' => $availability->onHand(),
				'reserved' => $availability->reserved(),
				'source_occurred_at' => $this->utcDateTime($availability->sourceOccurredAt()),
				'updated_at' => $this->utcDateTime($availability->updatedAt()),
			]],
			['product_id'],
			['stock_item_id', 'on_hand', 'reserved', 'source_occurred_at', 'updated_at']
		);
	}

	private function ensureTransactionIsActive(): void
	{
		if ($this->connection->transactionLevel() === 0) {
			throw new LogicException('Inventory availability projection writes require an active transaction.');
		}
	}

	private function toDateTime(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}

	private function utcDateTime(DateTimeImmutable $dateTime): string
	{
		return $dateTime
			->setTimezone(new DateTimeZone('UTC'))
			->format(self::DATE_FORMAT);
	}
}
