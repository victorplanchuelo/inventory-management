<?php

declare(strict_types=1);

namespace Manager\Inventory\Application\Stock;

use Manager\Inventory\Application\IntegrationEvent\StockItemIntegrationEventRecorder;
use Manager\Inventory\Domain\StockItem;
use Manager\Shared\Application\Transaction\TransactionalSession;

final readonly class StockItemTransaction
{
	private const ATTEMPTS = 3;

	public function __construct(
		private TransactionalSession $session,
		private StockItemIntegrationEventRecorder $recorder
	) {}

	/**
	 * @param callable(): list<StockItem> $operation
	 */
	public function execute(callable $operation): void
	{
		$this->session->execute(function () use ($operation): void {
			/** @var list<StockItem> $stockItems */
			$stockItems = $operation();

			foreach ($stockItems as $stockItem) {
				$this->recorder->record($stockItem);
			}
		}, self::ATTEMPTS);
	}
}
