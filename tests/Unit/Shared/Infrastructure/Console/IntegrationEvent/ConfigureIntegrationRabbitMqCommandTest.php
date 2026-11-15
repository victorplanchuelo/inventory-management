<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Console\IntegrationEvent;

use ArrayIterator;
use Illuminate\Console\Command;
use Manager\Shared\Application\Inbox\InboxExchangeValidator;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Console\IntegrationEvent\ConfigureIntegrationRabbitMqCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class ConfigureIntegrationRabbitMqCommandTest extends UnitTestCase
{
	#[Test]
	public function it_should_accept_an_array_of_subscribers(): void
	{
		$command = new ConfigureIntegrationRabbitMqCommand(
			new IntegrationEventRabbitMqConfigurer($this->app->make(RabbitMqConnection::class), 'integration_events'),
			new InboxExchangeValidator('integration_events', 'integration_events'),
			[]
		);

		$this->assertInstanceOf(ConfigureIntegrationRabbitMqCommand::class, $command);
	}

	#[Test]
	public function it_should_resolve_from_the_service_container(): void
	{
		$command = $this->app->make(ConfigureIntegrationRabbitMqCommand::class);

		$this->assertInstanceOf(ConfigureIntegrationRabbitMqCommand::class, $command);
	}

	#[Test]
	public function it_should_fail_when_the_inbox_and_outbox_exchanges_differ(): void
	{
		$command = new ConfigureIntegrationRabbitMqCommand(
			new IntegrationEventRabbitMqConfigurer($this->app->make(RabbitMqConnection::class), 'inbox_events'),
			new InboxExchangeValidator('inbox_events', 'outbox_events'),
			new ArrayIterator([])
		);
		$command->setLaravel($this->app);

		$tester = new CommandTester($command);
		$status = $tester->execute([]);

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('inbox_events', $tester->getDisplay());
		$this->assertStringContainsString('outbox_events', $tester->getDisplay());
	}
}
