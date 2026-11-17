<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Application\IntegrationEvent\Exception\InvalidIntegrationEventEnvelope;
use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventName;
use Manager\Shared\Application\IntegrationEvent\Exception\UnsupportedIntegrationEventVersion;
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
	public function it_should_reject_an_unsupported_event_name_and_preserve_the_envelope(): void
	{
		$data = IntegrationEventFixture::data(['eventName' => 'inventory.stock_removed']);

		try {
			$this->deserializer->deserialize((string) json_encode($data, JSON_THROW_ON_ERROR), $this->subscriber);
			$this->fail('An unsupported event name must be rejected.');
		} catch (UnsupportedIntegrationEventName $error) {
			$this->assertSame(IntegrationEventFixture::EVENT_ID, $error->envelope()->eventId);
			$this->assertSame('inventory.stock_removed', $error->envelope()->eventName);
		}
	}

	#[Test]
	public function it_should_reject_an_unsupported_event_version_and_preserve_the_envelope(): void
	{
		$data = IntegrationEventFixture::data(['eventVersion' => '2']);

		try {
			$this->deserializer->deserialize((string) json_encode($data, JSON_THROW_ON_ERROR), $this->subscriber);
			$this->fail('An unsupported event version must be rejected.');
		} catch (UnsupportedIntegrationEventVersion $error) {
			$this->assertSame(IntegrationEventFixture::EVENT_ID, $error->envelope()->eventId);
			$this->assertSame(IntegrationEventFixture::EVENT_NAME, $error->envelope()->eventName);
			$this->assertSame('2', $error->envelope()->eventVersion);
		}
	}

	#[Test]
	public function it_should_treat_contract_failures_as_permanent(): void
	{
		$data = IntegrationEventFixture::data(['eventVersion' => '2']);

		try {
			$this->deserializer->deserialize((string) json_encode($data, JSON_THROW_ON_ERROR), $this->subscriber);
			$this->fail('An unsupported event version must be rejected.');
		} catch (UnsupportedIntegrationEventVersion $error) {
			$this->assertInstanceOf(PermanentIntegrationEventFailure::class, $error);
		}
	}

	#[Test]
	public function it_should_reject_malformed_json(): void
	{
		$this->expectException(InvalidIntegrationEventEnvelope::class);

		$this->deserializer->deserialize('{"eventId":', $this->subscriber);
	}

	#[Test]
	public function it_should_treat_an_invalid_envelope_as_a_permanent_failure(): void
	{
		$data = IntegrationEventFixture::data(['occurredAt' => '2026-11-10T12:34:56+01:00']);

		try {
			$this->deserializer->deserialize((string) json_encode($data, JSON_THROW_ON_ERROR), $this->subscriber);
			$this->fail('An invalid occurredAt value must be rejected.');
		} catch (InvalidIntegrationEventEnvelope $error) {
			$this->assertInstanceOf(PermanentIntegrationEventFailure::class, $error);
		}
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
