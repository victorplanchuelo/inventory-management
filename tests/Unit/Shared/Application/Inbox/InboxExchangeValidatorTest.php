<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\Exception\IncompatibleInboxExchange;
use Manager\Shared\Application\Inbox\InboxExchangeValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxExchangeValidatorTest extends UnitTestCase
{
	#[Test]
	public function it_should_accept_identical_inbox_and_outbox_exchanges(): void
	{
		$validator = new InboxExchangeValidator('integration_events', 'integration_events');

		$validator->assertCompatible();

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function it_should_reject_different_exchanges_and_include_both_names(): void
	{
		$validator = new InboxExchangeValidator('inbox_events', 'outbox_events');

		try {
			$validator->assertCompatible();
			$this->fail('The validator should have rejected different exchanges.');
		} catch (IncompatibleInboxExchange $exception) {
			$this->assertStringContainsString('inbox_events', $exception->getMessage());
			$this->assertStringContainsString('outbox_events', $exception->getMessage());
		}
	}

	#[Test]
	public function it_should_reject_an_empty_inbox_exchange(): void
	{
		$validator = new InboxExchangeValidator('', 'integration_events');

		$this->expectException(IncompatibleInboxExchange::class);

		$validator->assertCompatible();
	}

	#[Test]
	public function it_should_reject_an_empty_outbox_exchange(): void
	{
		$validator = new InboxExchangeValidator('integration_events', '');

		$this->expectException(IncompatibleInboxExchange::class);

		$validator->assertCompatible();
	}
}
