# Orders Inventory Availability Projection

## 1. Purpose and ownership

The Orders bounded context owns a local read model of the current Inventory availability for each product. Orders exposes it through an advisory query API without reading Inventory tables, repositories or models.

The projection is eventually consistent. It stores only Inventory data received through the public integration events defined in [inventory-integration-events.md](inventory-integration-events.md) and, in a later phase, through a controlled Inventory snapshot export.

Inventory remains the source of truth for stock quantities. Orders must never treat the projection as authoritative for stock mutations.

## 2. Projection model

Table: `orders_inventory_availability`.

| Column | Type | Description |
|---|---|---|
| `product_id` | UUID primary key | Product identifier. One projection row per product. |
| `stock_item_id` | UUID, non-unique index | Inventory stock item the row was derived from. |
| `on_hand` | unsigned bigint | Total stock on hand. |
| `reserved` | unsigned bigint | Reserved stock. |
| `source_occurred_at` | datetime | UTC watermark of the applied Inventory state. |
| `updated_at` | datetime | UTC local projection update time. |

Rules:

- `available` is derived as `on_hand - reserved`; it is not persisted.
- `stock_item_id` is a non-unique index. `product_id` is the only unique key.
- The table has no `aggregate_version`. Inventory publishes no aggregate version and Orders does not invent one.
- Database checks enforce `on_hand >= 0`, `reserved >= 0` and `reserved <= on_hand`.
- The model assumes one stock item per product. If Inventory supports multiple stock items per product, this contract must be reviewed.
- All timestamps are stored as UTC wall-clock values in `DATETIME` columns, avoiding MySQL session-timezone conversions.

The domain object is `Manager\Orders\Domain\InventoryAvailability\InventoryAvailability`. It validates identifiers and quantities, derives `available()` and normalizes timestamps to UTC.

## 3. Event consumption

`InventoryStockProjectionSubscriber` consumes the following public Inventory events with contract version `1`:

| Event | Applied state |
|---|---|
| `inventory.stock_registered` | Resulting `onHand`, `reserved` and `available`. |
| `inventory.stock_added` | Resulting `onHand`, `reserved` and `available`. |
| `inventory.stock_reserved` | Resulting `onHand`, `reserved` and `available`. |
| `inventory.stock_released` | Resulting `onHand`, `reserved` and `available`. |

Consumer identity: `orders.inventory_availability_projection`.

The subscriber validates the envelope and payload before writing:

- `aggregateType` must be `stock_item`.
- `aggregateId` must equal `payload.stockItemId`.
- `stockItemId` and `productId` must be valid UUIDs.
- `onHand`, `reserved` and `available` must be integers.
- `onHand >= 0`, `reserved >= 0` and `reserved <= onHand`.
- `available` must equal `onHand - reserved`. The value is validated but not stored.

Operation-specific fields remain part of the published contract but are not required by this projection. Unknown payload fields are ignored for forward compatibility. Validation failures throw `InvalidInventoryStockEvent`, a permanent failure that is dead-lettered without retries.

Each event is processed inside the shared Inbox transaction. The projection write and the Inbox claim commit or roll back together. The repository requires an active transaction and never starts, commits or rolls back one itself.

## 4. Ordering policy and Phase 1 limitation

> **Phase 1 is a development happy path only and must not be deployed to production.**
>
> Phase 1 uses a last-arrival upsert. Out-of-order delivery can regress the projection to an older state. Phase 2 replaces it with a strict `sourceOccurredAt` guard before production deployment.

Orders uses `NoOrderingAggregateVersionPolicy` because Inventory publishes no `aggregateVersion`. Duplicate suppression relies on the Inbox unique `(consumer, event_id)` index.

The strict ordering policy planned for Phase 2 is:

- Apply an event only when its `sourceOccurredAt` is strictly newer than the stored `source_occurred_at`.
- Treat equal timestamps as stale and leave the row unchanged.
- Compare UTC values at second precision.
- Do not claim aggregate-version ordering. OD-002 remains open.

The projection never reserves an `aggregate_version` column. If Inventory later publishes an aggregate version, a new migration and an explicit contract change are required.

## 5. Query API

```http
GET /orders/inventory-availability/{productId}
```

The route only accepts UUID product identifiers. The query reads only `orders_inventory_availability`.

Existing projection:

```http
HTTP/1.1 200 OK
Content-Type: application/json
```

```json
{
  "productId": "7b0f4a3e-2f41-4f4a-9d5e-0f2a6c1b8d11",
  "stockItemId": "b5a1c2d3-4e5f-4a6b-8c7d-9e0f1a2b3c4d",
  "onHand": 10,
  "reserved": 2,
  "available": 8,
  "sourceOccurredAt": "2026-11-24T12:00:00Z",
  "projectionUpdatedAt": "2026-11-24T12:00:01Z",
  "advisory": true,
  "consistency": "eventually_consistent"
}
```

Missing projection:

```http
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
```

```json
{
  "type": "https://stockflow.example/problems/inventory-availability-not-found",
  "title": "Inventory availability projection not found",
  "status": 404,
  "detail": "No Inventory availability projection is available for this product yet. This does not mean that the product has no stock.",
  "advisory": true,
  "consistency": "eventually_consistent"
}
```

A missing projection must never be interpreted as zero stock. The endpoint must not query Inventory tables, repositories or models to fill missing data. Orders may not use this response to confirm or reject orders.

## 6. Initialization and reconciliation

A projection that never received the product's events has no row. Initialization and reconciliation use a controlled procedure:

1. Inventory exports a snapshot of its stock state with `schemaVersion`, `generatedAt` and one row per product.
2. Orders imports the snapshot file and applies every row through the same ordering guard as live events, inside one Orders transaction.

The snapshot `sourceOccurredAt` is the Inventory row `updated_at`, which is never earlier than the event `occurredAt` that produced the state. Re-importing the same snapshot is idempotent. Invalid rows or duplicate product identifiers fail the import without partial changes.

This procedure is implemented in Phase 3 of the Task005 plan. Historical Outbox replay is not assumed.

## 7. Operational notes

- Consumer queue and dead-letter routing follow the shared Inbox conventions in [inbox-operations.md](inbox-operations.md).
- The projection writes share the default application connection with the Inbox.
- Snapshot import may hold row locks. Run it during low traffic.
- A failed projection write rolls back both the projection row and the Inbox claim, allowing a safe retry or dead-letter.
