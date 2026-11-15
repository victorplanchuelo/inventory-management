<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Application\IntegrationEvent;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventEnvelopeDeserializer;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxEnvelopeContractTest extends UnitTestCase
{
	#[Test]
	public function it_should_round_trip_a_real_outbox_record_through_the_integration_event_contract(): void
	{
		$eventId = SimpleUuid::random()->value();
		$occurredAt = new DateTimeImmutable('2026-11-10 13:34:56', new DateTimeZone('UTC'));
		$record = new OutboxRecord(
			SimpleUuid::random()->value(),
			'inventory',
			$eventId,
			IntegrationEventFixture::EVENT_NAME,
			IntegrationEventFixture::EVENT_VERSION,
			'stock_item',
			IntegrationEventFixture::AGGREGATE_ID,
			7,
			'correlation-1',
			'causation-1',
			'{"sku":"A-1","quantity":2}',
			$occurredAt,
			new DateTimeImmutable('2026-11-10 13:34:57', new DateTimeZone('UTC')),
			null,
			0,
			null,
			null,
			null,
			null,
			null,
			OutboxStatus::PENDING,
			null,
		);

		$body = (new OutboxEnvelopeSerializer())->serialize($record);
		$subscriber = new TestIntegrationEventSubscriber(
			'test.contract',
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			static function (): void {}
		);

		$envelope = (new IntegrationEventEnvelopeDeserializer())->deserialize($body, $subscriber);

		$this->assertStringContainsString('"occurredAt":"2026-11-10T13:34:56Z"', $body);
		$this->assertSame($eventId, $envelope->eventId);
		$this->assertSame(IntegrationEventFixture::EVENT_NAME, $envelope->eventName);
		$this->assertSame(IntegrationEventFixture::EVENT_VERSION, $envelope->eventVersion);
		$this->assertSame('stock_item', $envelope->aggregateType);
		$this->assertSame(IntegrationEventFixture::AGGREGATE_ID, $envelope->aggregateId);
		$this->assertSame(7, $envelope->aggregateVersion);
		$this->assertSame('2026-11-10T13:34:56Z', $envelope->occurredAt->format('Y-m-d\TH:i:s\Z'));
		$this->assertSame(['sku' => 'A-1', 'quantity' => 2], $envelope->payload);
		$this->assertSame('correlation-1', $envelope->correlationId);
		$this->assertSame('causation-1', $envelope->causationId);
	}

	#[Test]
	public function it_should_accept_a_record_without_optional_metadata_or_aggregate_version(): void
	{
		$record = new OutboxRecord(
			SimpleUuid::random()->value(),
			'inventory',
			SimpleUuid::random()->value(),
			IntegrationEventFixture::EVENT_NAME,
			IntegrationEventFixture::EVENT_VERSION,
			'stock_item',
			IntegrationEventFixture::AGGREGATE_ID,
			null,
			null,
			null,
			'{}',
			new DateTimeImmutable('2026-11-10 13:34:56', new DateTimeZone('UTC')),
			new DateTimeImmutable('2026-11-10 13:34:57', new DateTimeZone('UTC')),
			null,
			0,
			null,
			null,
			null,
			null,
			null,
			OutboxStatus::PENDING,
			null,
		);

		$body = (new OutboxEnvelopeSerializer())->serialize($record);
		$envelope = (new IntegrationEventEnvelopeDeserializer())->deserialize(
			$body,
			new TestIntegrationEventSubscriber('test.contract', [], static function (): void {})
		);

		$this->assertStringNotContainsString('aggregateVersion', $body);
		$this->assertStringNotContainsString('correlationId', $body);
		$this->assertStringNotContainsString('causationId', $body);
		$this->assertNull($envelope->aggregateVersion);
		$this->assertNull($envelope->correlationId);
		$this->assertNull($envelope->causationId);
	}
}
