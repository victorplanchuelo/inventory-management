<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception\IntegrationEventPublicationFailed;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventPublicationState;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class IntegrationEventPublicationStateTest extends UnitTestCase
{
	#[Test]
	public function it_should_fail_when_the_broker_has_not_acknowledged_the_publication(): void
	{
		$this->expectException(IntegrationEventPublicationFailed::class);

		(new IntegrationEventPublicationState())->assertSuccessful();
	}

	#[Test]
	public function it_should_accept_an_acknowledged_publication(): void
	{
		$state = new IntegrationEventPublicationState();
		$state->acknowledge(1, false);

		$state->assertSuccessful();

		$this->addToAssertionCount(1);
	}

	#[Test]
	public function it_should_fail_when_the_broker_rejects_the_publication(): void
	{
		$state = new IntegrationEventPublicationState();
		$state->reject(1, false, true);

		$this->expectException(IntegrationEventPublicationFailed::class);

		$state->assertSuccessful();
	}

	#[Test]
	public function it_should_fail_when_the_broker_returns_the_publication_as_unroutable(): void
	{
		$state = new IntegrationEventPublicationState();
		$state->recordReturn(312, 'NO_ROUTE', 'integration_events', 'manager.integration.test');
		$state->acknowledge(1, false);

		try {
			$state->assertSuccessful();
			$this->fail('An unroutable publication must not be considered successful.');
		} catch (IntegrationEventPublicationFailed $error) {
			$this->assertStringContainsString('NO_ROUTE', $error->getMessage());
			$this->assertStringContainsString('integration_events', $error->getMessage());
			$this->assertStringContainsString('manager.integration.test', $error->getMessage());
		}
	}

	#[Test]
	public function it_should_clear_previous_results_when_reset(): void
	{
		$state = new IntegrationEventPublicationState();
		$state->acknowledge(1, false);
		$state->reset();

		$this->expectException(IntegrationEventPublicationFailed::class);

		$state->assertSuccessful();
	}
}
