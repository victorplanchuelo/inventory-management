<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\IntegrationEvent;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Domain\ValueObject\Quantity;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Domain\ObjectMother\QuantityMother;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockItemIntegrationEventRecorderTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_pull_domain_events_and_append_one_outbox_message_per_event(): void
	{
		$outbox = new CollectingOutboxRecorder();
		$recorder = $this->recorder($outbox);
		$stockItem = StockItemMother::create(initialQuantity: QuantityMother::create(20));
		$stockItem->addStock(new Quantity(5));

		$recorder->record($stockItem);

		$messages = $outbox->messages();

		self::assertCount(2, $messages);
		self::assertSame('inventory.stock_registered', $messages[0]->eventName());
		self::assertSame('inventory.stock_added', $messages[1]->eventName());
		self::assertSame([], $stockItem->pullDomainEvents());
	}

	#[Test]
	public function it_should_clear_the_aggregate_events_after_recording_them(): void
	{
		$outbox = new CollectingOutboxRecorder();
		$recorder = $this->recorder($outbox);
		$stockItem = StockItemMother::create();

		$recorder->record($stockItem);
		$recorder->record($stockItem);

		self::assertCount(1, $outbox->messages());
	}

	#[Test]
	public function it_should_propagate_outbox_append_failures(): void
	{
		$outbox = new class() implements OutboxRecorder {
			public function append(OutboxMessage $message): void
			{
				throw new RuntimeException('Outbox append failed.');
			}
		};
		$recorder = new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper());
		$stockItem = StockItemMother::create();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Outbox append failed.');

		$recorder->record($stockItem);
	}

	private function recorder(CollectingOutboxRecorder $outbox): StockItemIntegrationEventRecorder
	{
		return new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper());
	}
}
