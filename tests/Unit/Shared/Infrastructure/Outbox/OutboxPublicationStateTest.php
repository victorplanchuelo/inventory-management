<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationState;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxPublicationStateTest extends UnitTestCase
{
	#[Test]
	public function it_should_accept_a_positive_acknowledgement(): void
	{
		$state = new OutboxPublicationState();

		$this->assertFalse($state->acknowledge(1, false));

		$state->assertSuccessful();

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function it_should_reject_a_negative_acknowledgement(): void
	{
		$state = new OutboxPublicationState();
		$state->reject(1, false, false);

		$this->expectException(OutboxPublicationException::class);
		$this->expectExceptionMessage('negatively acknowledged');

		$state->assertSuccessful();
	}

	#[Test]
	public function it_should_reject_a_returned_message_as_unroutable(): void
	{
		$state = new OutboxPublicationState();
		$state->recordReturn(312, 'NO_ROUTE', 'integration_events', 'orders.order_confirmed',);

		$this->expectException(OutboxPublicationException::class);
		$this->expectExceptionMessage('unroutable');

		$state->assertSuccessful();
	}

	#[Test]
	public function it_should_reject_a_publication_without_confirmation(): void
	{
		$state = new OutboxPublicationState();

		$this->expectException(OutboxPublicationException::class);
		$this->expectExceptionMessage('did not confirm');

		$state->assertSuccessful();
	}

	#[Test]
	public function it_should_clear_the_previous_publication_outcome_on_reset(): void
	{
		$state = new OutboxPublicationState();
		$state->reject(1, false, false);
		$state->reset();

		$this->assertFalse($state->acknowledge(2, false));

		$state->assertSuccessful();

		$this->addToAssertionCount(1);
	}
}
