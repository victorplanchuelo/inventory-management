<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\Exception\InvalidInboxConsumer;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxConsumerValidatorTest extends UnitTestCase
{
	#[Test]
	public function it_should_accept_a_consumer_in_a_configured_context(): void
	{
		$validator = new InboxConsumerValidator(['inventory' => 'inventory_inbox']);

		$validator->assertIsValid('inventory.stock_projection');

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function it_should_reject_a_consumer_without_a_context_prefix(): void
	{
		$validator = new InboxConsumerValidator(['inventory' => 'inventory_inbox']);

		$this->expectException(InvalidInboxConsumer::class);

		$validator->assertIsValid('stock_projection');
	}

	#[Test]
	public function it_should_reject_a_consumer_for_an_unconfigured_context(): void
	{
		$validator = new InboxConsumerValidator(['inventory' => 'inventory_inbox']);

		$this->expectException(InvalidInboxConsumer::class);

		$validator->assertIsValid('orders.order_projection');
	}

	#[Test]
	public function it_should_reject_an_overlong_consumer_name(): void
	{
		$validator = new InboxConsumerValidator(['inventory' => 'inventory_inbox']);

		$this->expectException(InvalidInboxConsumer::class);

		$validator->assertIsValid('inventory.' . str_repeat('a', 120));
	}

	#[Test]
	public function it_should_reject_an_invalid_consumer_name(): void
	{
		$validator = new InboxConsumerValidator(['inventory' => 'inventory_inbox']);

		$this->expectException(InvalidInboxConsumer::class);

		$validator->assertIsValid('inventory.stock projection');
	}
}
