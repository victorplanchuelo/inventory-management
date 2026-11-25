<?php

declare(strict_types=1);

namespace Tests\Integration\Orders\Infrastructure\IntegrationEvent;

use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use Manager\Orders\Infrastructure\IntegrationEvent\InventoryStockProjectionSubscriber;
use Manager\Orders\Infrastructure\Persistence\Repositories\MySqlInventoryAvailabilityRepository;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\Orders\Infrastructure\IntegrationEvent\Support\FailingInventoryAvailabilityRepository;
use Tests\Integration\Orders\Infrastructure\IntegrationEvent\Support\PartiallyWritingInventoryAvailabilityRepository;
use Tests\Integration\Orders\OrdersIntegrationTestCase;
use Tests\Shared\Domain\UuidMother;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;

final class InventoryStockProjectionConsumerTest extends OrdersIntegrationTestCase
{
	private const CONSUMER = 'orders.inventory_availability_projection';

	private const EVENT_NAMES = [
		'inventory.stock_registered',
		'inventory.stock_added',
		'inventory.stock_reserved',
		'inventory.stock_released',
	];

	private InboxProcessor $processor;
	private InventoryStockProjectionSubscriber $subscriber;

	/** @var list<string> */
	private array $processedProductIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->processedProductIds = [];
		$this->processor = $this->service(InboxProcessor::class);
		$this->subscriber = $this->service(InventoryStockProjectionSubscriber::class);
	}

	#[Test]
	public function it_should_commit_the_projection_and_inbox_record_together(): void
	{
		$event = $this->event('inventory.stock_added');

		$result = $this->processor->process($event, $this->subscriber);

		self::assertSame(InboxProcessingResult::PROCESSED, $result);

		$projection = $this->projectionRow($event->payload['productId']);

		self::assertNotNull($projection);
		self::assertSame($event->payload['stockItemId'], $projection->stock_item_id);
		self::assertSame(10, (int) $projection->on_hand);
		self::assertSame(3, (int) $projection->reserved);
		self::assertSame('2026-11-24 12:00:00', $projection->source_occurred_at);

		$inboxRow = $this->inboxRow($event->eventId);

		self::assertNotNull($inboxRow);
		self::assertNotNull($inboxRow->processed_at);
		self::assertSame('processed', $inboxRow->outcome);
		self::assertNull($inboxRow->aggregate_version);
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_not_apply_a_duplicate_event_twice(): void
	{
		$event = $this->event('inventory.stock_added');

		$first = $this->processor->process($event, $this->subscriber);
		$second = $this->processor->process($event, $this->subscriber);

		self::assertSame(InboxProcessingResult::PROCESSED, $first);
		self::assertSame(InboxProcessingResult::DUPLICATE, $second);
		self::assertSame(1, $this->connection->table('orders_inbox')->where('event_id', $event->eventId)->count());
		self::assertSame(
			1,
			$this->connection->table('orders_inventory_availability')
				->where('product_id', $event->payload['productId'])
				->count()
		);

		$projection = $this->projectionRow($event->payload['productId']);

		self::assertNotNull($projection);
		self::assertSame(10, (int) $projection->on_hand);
		self::assertSame(3, (int) $projection->reserved);
	}

	#[Test]
	public function it_should_roll_back_the_projection_and_inbox_when_the_projection_write_fails(): void
	{
		$event = $this->event('inventory.stock_added');
		$subscriber = $this->subscriberWith(
			new FailingInventoryAvailabilityRepository($this->service(MySqlInventoryAvailabilityRepository::class))
		);

		try {
			$this->processor->process($event, $subscriber);
			self::fail('A projection write failure was expected.');
		} catch (RuntimeException $exception) {
			self::assertSame('projection write failed', $exception->getMessage());
		}

		self::assertNull($this->projectionRow($event->payload['productId']));
		self::assertNull($this->inboxRow($event->eventId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_leave_no_projection_and_no_inbox_record_when_the_projection_write_fails(): void
	{
		$event = $this->event('inventory.stock_added');
		$subscriber = $this->subscriberWith(
			new PartiallyWritingInventoryAvailabilityRepository($this->service(MySqlInventoryAvailabilityRepository::class))
		);

		try {
			$this->processor->process($event, $subscriber);
			self::fail('A projection write failure was expected.');
		} catch (RuntimeException $exception) {
			self::assertSame('projection write failed after persisting the row', $exception->getMessage());
		}

		self::assertNull($this->projectionRow($event->payload['productId']));
		self::assertNull($this->inboxRow($event->eventId));
		self::assertSame(0, $this->connection->transactionLevel());
	}

	#[Test]
	public function it_should_process_all_four_supported_event_types(): void
	{
		foreach (self::EVENT_NAMES as $eventName) {
			$event = $this->event($eventName, onHand: 20, reserved: 5);

			self::assertSame(InboxProcessingResult::PROCESSED, $this->processor->process($event, $this->subscriber));

			$projection = $this->projectionRow($event->payload['productId']);

			self::assertNotNull($projection);
			self::assertSame(20, (int) $projection->on_hand);
			self::assertSame(5, (int) $projection->reserved);
		}

		self::assertSame(
			4,
			$this->connection->table('orders_inventory_availability')
				->whereIn('product_id', $this->processedProductIds)
				->count()
		);
	}

	private function subscriberWith(InventoryAvailabilityRepository $repository): InventoryStockProjectionSubscriber
	{
		$this->app->instance(InventoryAvailabilityRepository::class, $repository);

		return $this->app->make(InventoryStockProjectionSubscriber::class);
	}

	private function event(string $eventName, int $onHand = 10, int $reserved = 3): IntegrationEventEnvelope
	{
		$eventId = UuidMother::create();
		$productId = UuidMother::create();
		$stockItemId = UuidMother::create();

		$this->trackEvent($eventId);
		$this->trackProduct($productId);
		$this->processedProductIds[] = $productId;

		return IntegrationEventFixture::envelope([
			'eventId' => $eventId,
			'eventName' => $eventName,
			'aggregateId' => $stockItemId,
			'aggregateVersion' => null,
			'occurredAt' => '2026-11-24T12:00:00Z',
			'payload' => [
				'stockItemId' => $stockItemId,
				'productId' => $productId,
				'onHand' => $onHand,
				'reserved' => $reserved,
				'available' => $onHand - $reserved,
			],
		]);
	}

	private function projectionRow(string $productId): ?object
	{
		return $this->connection->table('orders_inventory_availability')
			->where('product_id', $productId)
			->first();
	}

	private function inboxRow(string $eventId): ?object
	{
		return $this->connection->table('orders_inbox')
			->where('consumer', self::CONSUMER)
			->where('event_id', $eventId)
			->first();
	}
}
