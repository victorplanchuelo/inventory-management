<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventEnvelopeDeserializer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class IntegrationEventEnvelopeDeserializerTest extends UnitTestCase
{
	private IntegrationEventEnvelopeDeserializer $deserializer;
	private TestIntegrationEventSubscriber $subscriber;

	protected function setUp(): void
	{
		parent::setUp();

		$this->deserializer = new IntegrationEventEnvelopeDeserializer();
		$this->subscriber = new TestIntegrationEventSubscriber(
			'test.deserializer',
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			static function (): void {},
		);
	}

	#[Test]
	public function it_should_deserialize_a_valid_envelope(): void
	{
		$envelope = $this->deserializer->deserialize(
			(string) json_encode(IntegrationEventFixture::data(), JSON_THROW_ON_ERROR),
			$this->subscriber
		);

		$this->assertSame(IntegrationEventFixture::EVENT_ID, $envelope->eventId);
		$this->assertSame(IntegrationEventFixture::OCCURRED_AT, $envelope->occurredAt->format('Y-m-d\TH:i:s\Z'));
	}

	#[Test]
	public function it_should_ignore_unknown_top_level_fields(): void
	{
		$data = IntegrationEventFixture::data(['unknownField' => ['ignored' => true]]);

		$envelope = $this->deserializer->deserialize(
			(string) json_encode($data, JSON_THROW_ON_ERROR),
			$this->subscriber
		);

		$this->assertSame(IntegrationEventFixture::EVENT_ID, $envelope->eventId);
	}

	#[Test]
	public function it_should_reject_malformed_json(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		$this->deserializer->deserialize('{"eventId":', $this->subscriber);
	}

	#[Test]
	public function it_should_reject_a_non_object_body(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		$this->deserializer->deserialize('[{"eventId":"x"}]', $this->subscriber);
	}

	#[Test]
	public function it_should_reject_an_empty_body(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		$this->deserializer->deserialize('', $this->subscriber);
	}

	#[Test]
	public function it_should_reject_a_scalar_json_body(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		$this->deserializer->deserialize('"event"', $this->subscriber);
	}
}
