<?php

declare(strict_types=1);

namespace Tests\Unit\Orders\Infrastructure\IntegrationEvent;

use Manager\Orders\Application\InventoryAvailability\Update\Exception\InvalidInventoryStockEvent;
use Manager\Orders\Application\InventoryAvailability\Update\UpdateInventoryAvailability;
use Manager\Orders\Infrastructure\IntegrationEvent\InventoryStockProjectionSubscriber;
use Manager\Shared\Application\Inbox\NoOrderingAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Domain\UuidMother;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Unit\Orders\Application\Support\RecordingInventoryAvailabilityRepository;
use Tests\Unit\Orders\OrdersUnitTestCase;

final class InventoryStockProjectionSubscriberTest extends OrdersUnitTestCase
{
	#[Test]
	public function it_should_subscribe_to_the_four_inventory_stock_events_with_contract_version_one(): void
	{
		$subscriber = $this->subscriber(new RecordingInventoryAvailabilityRepository());

		self::assertSame([
			'inventory.stock_registered' => ['1'],
			'inventory.stock_added' => ['1'],
			'inventory.stock_reserved' => ['1'],
			'inventory.stock_released' => ['1'],
		], $subscriber->subscribedTo());
	}

	#[Test]
	public function it_should_use_the_orders_consumer_name(): void
	{
		$subscriber = $this->subscriber(new RecordingInventoryAvailabilityRepository());

		self::assertSame('orders.inventory_availability_projection', $subscriber->consumerName());
	}

	#[Test]
	public function it_should_use_the_no_ordering_policy(): void
	{
		$subscriber = $this->subscriber(new RecordingInventoryAvailabilityRepository());

		self::assertInstanceOf(NoOrderingAggregateVersionPolicy::class, $subscriber->orderingPolicy());
	}

	#[Test]
	public function it_should_map_the_stock_registered_event_payload(): void
	{
		$this->assertEventIsMapped('inventory.stock_registered');
	}

	#[Test]
	public function it_should_map_the_stock_added_event_payload(): void
	{
		$this->assertEventIsMapped('inventory.stock_added');
	}

	#[Test]
	public function it_should_map_the_stock_reserved_event_payload(): void
	{
		$this->assertEventIsMapped('inventory.stock_reserved');
	}

	#[Test]
	public function it_should_map_the_stock_released_event_payload(): void
	{
		$this->assertEventIsMapped('inventory.stock_released');
	}

	#[Test]
	public function it_should_reject_an_aggregate_type_other_than_stock_item(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', envelopeOverrides: ['aggregateType' => 'order']);

		$this->assertInvalidEvent($repository, $event, 'Unsupported Inventory stock aggregate type <order>.');
	}

	#[Test]
	public function it_should_reject_a_payload_stock_item_id_that_does_not_match_the_aggregate_id(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$stockItemId = UuidMother::create();
		$event = $this->event(
			'inventory.stock_added',
			stockItemId: $stockItemId,
			envelopeOverrides: ['aggregateId' => UuidMother::create()],
		);

		$this->assertInvalidEvent($repository, $event, 'does not match the payload stock item identifier');
	}

	#[Test]
	#[DataProvider('missingPayloadFields')]
	public function it_should_reject_missing_payload_fields(string $field): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', removePayloadFields: [$field]);

		$this->assertInvalidEvent($repository, $event, sprintf('missing the <%s> field', $field));
	}

	#[Test]
	#[DataProvider('wronglyTypedPayloadFields')]
	public function it_should_reject_wrongly_typed_payload_fields(string $field, mixed $value): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', payloadOverrides: [$field => $value]);

		$this->assertInvalidEvent($repository, $event, sprintf('invalid <%s> field', $field));
	}

	#[Test]
	public function it_should_reject_negative_quantities(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', payloadOverrides: [
			'onHand' => -1,
			'reserved' => 0,
			'available' => -1,
		]);

		$this->assertInvalidEvent($repository, $event, 'quantity <-1> cannot be negative');
	}

	#[Test]
	public function it_should_reject_reserved_greater_than_on_hand(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', payloadOverrides: [
			'onHand' => 5,
			'reserved' => 6,
			'available' => 0,
		]);

		$this->assertInvalidEvent($repository, $event, 'cannot be greater than the on-hand quantity <5>');
	}

	#[Test]
	public function it_should_reject_an_inconsistent_available_quantity(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', payloadOverrides: ['available' => 9]);

		$this->assertInvalidEvent($repository, $event, 'does not match the expected value <8>');
	}

	#[Test]
	public function it_should_throw_a_permanent_failure_for_invalid_payloads(): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event('inventory.stock_added', payloadOverrides: ['onHand' => 'ten']);

		$this->assertInvalidEvent($repository, $event, 'invalid <onHand> field');
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function missingPayloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId'];
		yield 'product id' => ['productId'];
		yield 'on hand' => ['onHand'];
		yield 'reserved' => ['reserved'];
		yield 'available' => ['available'];
	}

	/**
	 * @return iterable<string, array{string, mixed}>
	 */
	public static function wronglyTypedPayloadFields(): iterable
	{
		yield 'stock item id' => ['stockItemId', 123];
		yield 'product id' => ['productId', null];
		yield 'on hand' => ['onHand', '10'];
		yield 'reserved' => ['reserved', '2'];
		yield 'available' => ['available', '8'];
	}

	private function assertEventIsMapped(string $eventName): void
	{
		$repository = new RecordingInventoryAvailabilityRepository();
		$event = $this->event($eventName);

		$this->subscriber($repository)($event);

		$applied = $repository->applied();
		self::assertCount(1, $applied);
		self::assertSame($event->payload['productId'], $applied[0]->productId());
		self::assertSame($event->payload['stockItemId'], $applied[0]->stockItemId());
		self::assertSame(10, $applied[0]->onHand());
		self::assertSame(2, $applied[0]->reserved());
		self::assertSame(8, $applied[0]->available());
		self::assertSame('2026-11-24T12:00:00Z', $applied[0]->sourceOccurredAt()->format('Y-m-d\TH:i:s\Z'));
	}

	private function assertInvalidEvent(
		RecordingInventoryAvailabilityRepository $repository,
		IntegrationEventEnvelope $event,
		string $expectedMessage
	): void {
		try {
			$this->subscriber($repository)($event);
			self::fail('An InvalidInventoryStockEvent was expected.');
		} catch (InvalidInventoryStockEvent $exception) {
			self::assertInstanceOf(PermanentIntegrationEventFailure::class, $exception);
			self::assertStringContainsString($expectedMessage, $exception->getMessage());
		}

		self::assertSame([], $repository->applied());
	}

	private function subscriber(
		RecordingInventoryAvailabilityRepository $repository
	): InventoryStockProjectionSubscriber {
		return new InventoryStockProjectionSubscriber(new UpdateInventoryAvailability($repository));
	}

	/**
	 * @param array<string, mixed> $payloadOverrides
	 * @param array<string, mixed> $envelopeOverrides
	 * @param list<string> $removePayloadFields
	 */
	private function event(
		string $eventName,
		?string $stockItemId = null,
		?string $productId = null,
		array $payloadOverrides = [],
		array $envelopeOverrides = [],
		array $removePayloadFields = [],
	): IntegrationEventEnvelope {
		$stockItemId ??= UuidMother::create();
		$productId ??= UuidMother::create();

		$payload = array_merge([
			'stockItemId' => $stockItemId,
			'productId' => $productId,
			'onHand' => 10,
			'reserved' => 2,
			'available' => 8,
		], $payloadOverrides);

		foreach ($removePayloadFields as $field) {
			unset($payload[$field]);
		}

		return IntegrationEventFixture::envelope(array_merge([
			'eventName' => $eventName,
			'aggregateId' => $stockItemId,
			'occurredAt' => '2026-11-24T12:00:00Z',
			'payload' => $payload,
		], $envelopeOverrides));
	}
}
