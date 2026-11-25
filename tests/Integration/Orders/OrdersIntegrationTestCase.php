<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use Illuminate\Database\Connection;
use LogicException;
use Manager\Shared\Infrastructure\Inbox\InboxConnectionFactory;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

abstract class OrdersIntegrationTestCase extends InfrastructureTestCase
{
	protected Connection $connection;

	/** @var list<string> */
	private array $productIds = [];

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->productIds = [];
		$this->eventIds = [];
		$this->connection = app(InboxConnectionFactory::class)->connection();
	}

	protected function tearDown(): void
	{
		try {
			$this->cleanUp();
		} finally {
			parent::tearDown();
		}
	}

	protected function trackProduct(string $productId): void
	{
		$this->productIds[] = $productId;
	}

	protected function trackEvent(string $eventId): void
	{
		$this->eventIds[] = $eventId;
	}

	protected function withSessionTimeZone(string $timeZone, callable $operation): void
	{
		$originalTimeZone = $this->sessionTimeZone();
		$this->setSessionTimeZone($timeZone);

		try {
			$operation();
		} finally {
			$this->setSessionTimeZone($originalTimeZone);
		}
	}

	private function sessionTimeZone(): string
	{
		$row = $this->connection->selectOne('SELECT @@session.time_zone AS time_zone');

		if (!is_object($row) || !isset($row->time_zone) || !is_string($row->time_zone)) {
			throw new LogicException('The MySQL session time zone could not be read.');
		}

		return $row->time_zone;
	}

	private function setSessionTimeZone(string $timeZone): void
	{
		$this->connection->statement(sprintf(
			'SET SESSION time_zone = %s',
			$this->connection->getPdo()->quote($timeZone)
		));
	}

	private function cleanUp(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('orders_inbox')
				->whereIn('event_id', $this->eventIds)
				->delete();
		}

		if ($this->productIds !== []) {
			$this->connection->table('orders_inventory_availability')
				->whereIn('product_id', $this->productIds)
				->delete();
		}
	}
}
