<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use Manager\Shared\Infrastructure\Outbox\UnknownOutboxProducerException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class MySqlOutboxRecorderTest extends UnitTestCase
{
	#[Test]
	public function it_should_reject_an_unknown_producer_before_touching_the_connection(): void
	{
		/** @var Connection&MockInterface $connection */
		$connection = Mockery::mock(Connection::class);
		$connection->shouldNotReceive('table');

		$recorder = new MySqlOutboxRecorder($connection, new OutboxProducerValidator(['inventory']));

		$this->expectException(UnknownOutboxProducerException::class);

		$recorder->append($this->message('shipping'));
	}

	private function message(string $producer): OutboxMessage
	{
		return new OutboxMessage(
			'5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01',
			'orders.order_confirmed',
			'1',
			$producer,
			'order',
			'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10',
			'{"orderId":"abc"}',
			new DateTimeImmutable('2026-11-10 12:34:56', new DateTimeZone('UTC')),
		);
	}
}
