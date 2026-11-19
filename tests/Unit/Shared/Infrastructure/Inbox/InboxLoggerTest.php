<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Inbox;

use Manager\Shared\Infrastructure\Inbox\InboxLogger;
use Manager\Shared\Infrastructure\Outbox\OutboxErrorSanitizer;
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
		$this->logger = new InboxLogger($this->collectingLogger, new OutboxErrorSanitizer());
	}

	#[Test]
	public function it_should_log_only_structured_metadata_and_never_the_payload(): void
	{
		$event = IntegrationEventFixture::envelope([
			'payload' => ['secret' => 'super-secret-payload-value'],
		]);

		$this->logger->recordProcessed($event, 'inventory.stock_projection', 0.125);

		$records = $this->collectingLogger->records();

		$this->assertCount(1, $records);
		$this->assertSame('inbox.event.processed', $records[0]['message']);
		$this->assertSame('info', $records[0]['level']);
		$this->assertSame('super-secret-payload-value', $event->payload['secret']);
		$this->assertArrayNotHasKey('payload', $records[0]['context']);
		$this->assertStringNotContainsString('super-secret-payload-value', $this->collectingLogger->json());
		$this->assertSame(IntegrationEventFixture::EVENT_ID, $records[0]['context']['event_id']);
		$this->assertSame('inventory.stock_projection', $records[0]['context']['consumer']);
		$this->assertSame('processed', $records[0]['context']['outcome']);
		$this->assertSame(125.0, $records[0]['context']['latency_ms']);
	}

	#[Test]
	public function it_should_log_stale_events_with_their_own_outcome(): void
	{
		$this->logger->recordStale(IntegrationEventFixture::envelope(), 'inventory.stock_projection', 0.05);

		$records = $this->collectingLogger->records();

		$this->assertSame('inbox.event.stale', $records[0]['message']);
		$this->assertSame('warning', $records[0]['level']);
		$this->assertSame('stale', $records[0]['context']['outcome']);
		$this->assertSame(50.0, $records[0]['context']['latency_ms']);
	}

	#[Test]
	public function it_should_sanitize_error_messages_and_keep_them_on_one_line(): void
	{
		$this->logger->recordRetry(
			IntegrationEventFixture::envelope(),
			'inventory.stock_projection',
			'manager.integration.inventory.stock_projection',
			1,
			new RuntimeException("sensitive failure password=super-secret-value\nsecond line")
		);

		$records = $this->collectingLogger->records();

		$this->assertSame(RuntimeException::class, $records[0]['context']['error_class']);
		$this->assertStringContainsString('[REDACTED]', $records[0]['context']['error_message']);
		$this->assertStringNotContainsString('super-secret-value', $this->collectingLogger->json());
		$this->assertStringNotContainsString("\n", (string) $records[0]['context']['error_message']);
		$this->assertSame('inventory.stock_projection', $records[0]['context']['consumer']);
	}

	#[Test]
	public function it_should_log_a_malformed_event_without_the_body(): void
	{
		$this->logger->recordMalformed(
			'manager.integration.inventory.stock_projection',
			new RuntimeException('raw payload: password=super-secret-value'),
			'inventory.stock_projection'
		);

		$records = $this->collectingLogger->records();

		$this->assertSame('inbox.event.malformed', $records[0]['message']);
		$this->assertSame('inventory.stock_projection', $records[0]['context']['consumer']);
		$this->assertStringContainsString('[REDACTED]', $records[0]['context']['error_message']);
		$this->assertStringNotContainsString('super-secret-value', $this->collectingLogger->json());
	}

	#[Test]
	public function it_should_not_log_when_disabled(): void
	{
		$logger = new InboxLogger($this->collectingLogger, new OutboxErrorSanitizer(), false);

		$logger->recordProcessed(IntegrationEventFixture::envelope(), 'inventory.stock_projection');
		$logger->recordDuplicate(IntegrationEventFixture::envelope(), 'inventory.stock_projection');
		$logger->recordStale(IntegrationEventFixture::envelope(), 'inventory.stock_projection');
		$logger->recordRetry(null, 'inventory.stock_projection', 'queue', 1, new RuntimeException('error'));
		$logger->recordDeadLetter(null, 'inventory.stock_projection', 'queue', new RuntimeException('error'));
		$logger->recordMalformed('queue', new RuntimeException('error'));

		$this->assertSame([], $this->collectingLogger->records());
	}
}
