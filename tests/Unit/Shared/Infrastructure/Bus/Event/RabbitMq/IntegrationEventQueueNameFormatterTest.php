<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventSubscriberLocator;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class IntegrationEventQueueNameFormatterTest extends UnitTestCase
{
	#[Test]
	public function it_should_build_the_main_retry_and_dead_letter_queue_names(): void
	{
		$formatter = new IntegrationEventQueueNameFormatter();
		$subscriber = $this->subscriber('inventory.stock_projection');

		$this->assertSame('manager.integration.inventory.stock_projection', $formatter->format($subscriber));
		$this->assertSame('retry.manager.integration.inventory.stock_projection', $formatter->formatRetry($subscriber));
		$this->assertSame(
			'dead_letter.manager.integration.inventory.stock_projection',
			$formatter->formatDeadLetter($subscriber)
		);
	}

	#[Test]
	public function it_should_find_a_subscriber_by_its_queue_name(): void
	{
		$formatter = new IntegrationEventQueueNameFormatter();
		$first = $this->subscriber('inventory.stock_projection');
		$second = $this->subscriber('orders.order_projection');
		$locator = new IntegrationEventSubscriberLocator([$first, $second]);

		$this->assertSame($second, $locator->withQueueNamed($formatter->format($second)));
		$this->assertSame([$first, $second], $locator->all());
	}

	#[Test]
	public function it_should_fail_for_an_unknown_queue_name(): void
	{
		$locator = new IntegrationEventSubscriberLocator([$this->subscriber('inventory.stock_projection')]);

		$this->expectException(RuntimeException::class);

		$locator->withQueueNamed('manager.integration.orders.order_projection');
	}

	private function subscriber(string $consumerName): TestIntegrationEventSubscriber
	{
		return new TestIntegrationEventSubscriber($consumerName, ['inventory.stock_added' => [
			'1',
		]], static function (): void {});
	}
}
