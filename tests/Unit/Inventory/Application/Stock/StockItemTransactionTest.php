<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Application\Stock;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventMapper;
use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Application\Stock\StockItemTransaction;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Unit\Inventory\Application\Support\CollectingOutboxRecorder;
use Tests\Unit\Inventory\Domain\ObjectMother\StockItemMother;
use Tests\Unit\Inventory\InventoryUnitTestCase;

final class StockItemTransactionTest extends InventoryUnitTestCase
{
	#[Test]
	public function it_should_execute_the_operation_through_the_transactional_session_with_three_attempts(): void
	{
		$session = $this->mockClass(TransactionalSession::class);
		$session->shouldReceive('execute')
			->once()
			->with(Mockery::on(static fn (mixed $operation): bool => is_callable($operation)), 3)
			->andReturnUsing(static fn (callable $operation): mixed => $operation());

		$outbox = new CollectingOutboxRecorder();
		$transaction = $this->transaction($session, $outbox);

		$transaction->execute(static fn (): array => []);

		self::assertSame([], $outbox->messages());
	}

	#[Test]
	public function it_should_record_the_events_of_every_returned_stock_item(): void
	{
		$session = $this->immediateSession();
		$outbox = new CollectingOutboxRecorder();
		$transaction = $this->transaction($session, $outbox);
		$first = StockItemMother::create();
		$second = StockItemMother::create();

		$transaction->execute(static fn (): array => [$first, $second]);

		$messages = $outbox->messages();

		self::assertCount(2, $messages);
		self::assertSame('inventory.stock_registered', $messages[0]->eventName());
		self::assertSame('inventory.stock_registered', $messages[1]->eventName());
	}

	#[Test]
	public function it_should_propagate_operation_failures_without_recording_events(): void
	{
		$session = $this->immediateSession();
		$outbox = $this->mockClass(OutboxRecorder::class);
		$outbox->shouldNotReceive('append');
		$transaction = $this->transaction($session, $outbox);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('operation failed');

		$transaction->execute(static function (): array {
			throw new RuntimeException('operation failed');
		});
	}

	#[Test]
	public function it_should_propagate_recorder_failures(): void
	{
		$session = $this->immediateSession();
		$outbox = $this->mockClass(OutboxRecorder::class);
		$outbox->shouldReceive('append')
			->once()
			->andThrow(new RuntimeException('outbox failed'));
		$transaction = $this->transaction($session, $outbox);
		$stockItem = StockItemMother::create();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('outbox failed');

		$transaction->execute(static fn (): array => [$stockItem]);
	}

	#[Test]
	public function it_should_do_nothing_when_the_operation_returns_an_empty_list(): void
	{
		$session = $this->immediateSession();
		$outbox = $this->mockClass(OutboxRecorder::class);
		$outbox->shouldNotReceive('append');
		$transaction = $this->transaction($session, $outbox);

		$transaction->execute(static fn (): array => []);
	}

	private function immediateSession(): TransactionalSession
	{
		$session = $this->mockClass(TransactionalSession::class);
		$session->shouldReceive('execute')
			->once()
			->with(Mockery::on(static fn (mixed $operation): bool => is_callable($operation)), 3)
			->andReturnUsing(static fn (callable $operation): mixed => $operation());

		return $session;
	}

	private function transaction(TransactionalSession $session, OutboxRecorder $outbox): StockItemTransaction
	{
		return new StockItemTransaction(
			$session,
			new StockItemIntegrationEventRecorder($outbox, new StockItemIntegrationEventMapper())
		);
	}
}
