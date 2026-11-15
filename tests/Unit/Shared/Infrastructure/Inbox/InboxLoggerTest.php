<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Inbox;

use Manager\Shared\Infrastructure\Inbox\InboxLogger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\PhpUnit\CollectingLogger;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxLoggerTest extends UnitTestCase
{
	private CollectingLogger $collectingLogger;
	private InboxLogger $logger;

	protected function setUp(): void
	{
		parent::setUp();

		$this->collectingLogger = new CollectingLogger();
		$this->logger = new InboxLogger($this->collectingLogger);
	}

	#[Test]
	public function it_should_log_only_structured_metadata_and_never_the_payload(): void
	{
		$event = IntegrationEventFixture::envelope([
			'payload' => ['secret' => 'super-secret-payload-value'],
		]);

		$this->logger->recordProcessed($event, 'inventory.stock_projection');

		$records = $this->collectingLogger->records();

		$this->assertCount(1, $records);
		$this->assertSame('inbox.event.processed', $records[0]['message']);
		$this->assertSame('super-secret-payload-value', $event->payload['secret']);
		$this->assertArrayNotHasKey('payload', $records[0]['context']);
		$this->assertStringNotContainsString('super-secret-payload-value', $this->collectingLogger->json());
		$this->assertSame(IntegrationEventFixture::EVENT_ID, $records[0]['context']['event_id']);
		$this->assertSame('inventory.stock_projection', $records[0]['context']['consumer']);
	}

	#[Test]
	public function it_should_log_the_error_class_without_the_error_message(): void
	{
		$this->logger->recordRetry(
			IntegrationEventFixture::envelope(),
			'manager.integration.inventory.stock_projection',
			1,
			new RuntimeException('sensitive failure details')
		);

		$records = $this->collectingLogger->records();

		$this->assertSame(RuntimeException::class, $records[0]['context']['error_class']);
		$this->assertArrayNotHasKey('error_message', $records[0]['context']);
		$this->assertStringNotContainsString('sensitive failure details', $this->collectingLogger->json());
	}

	#[Test]
	public function it_should_log_a_malformed_event_without_the_body(): void
	{
		$this->logger->recordMalformed(
			'manager.integration.inventory.stock_projection',
			new RuntimeException('raw payload: secret')
		);

		$records = $this->collectingLogger->records();

		$this->assertSame('inbox.event.malformed', $records[0]['message']);
		$this->assertStringNotContainsString('raw payload: secret', $this->collectingLogger->json());
	}

	#[Test]
	public function it_should_not_log_when_disabled(): void
	{
		$logger = new InboxLogger($this->collectingLogger, false);

		$logger->recordProcessed(IntegrationEventFixture::envelope(), 'inventory.stock_projection');
		$logger->recordDuplicate(IntegrationEventFixture::envelope(), 'inventory.stock_projection');
		$logger->recordRetry(null, 'queue', 1, new RuntimeException('error'));
		$logger->recordDeadLetter(null, 'queue', new RuntimeException('error'));
		$logger->recordMalformed('queue', new RuntimeException('error'));

		$this->assertSame([], $this->collectingLogger->records());
	}
}
