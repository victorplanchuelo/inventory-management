<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedPublisher;
use Manager\Shared\Infrastructure\Outbox\OutboxEnvelopeSerializer;
use Manager\Shared\Infrastructure\Outbox\OutboxPublicationException;
use Manager\Shared\Infrastructure\Outbox\OutboxRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class RabbitMqConfirmedPublisherTest extends InfrastructureTestCase
{
	private AMQPConnection $connection;
	private AMQPChannel $channel;
	private AMQPExchange $exchange;
	private ?AMQPQueue $queue = null;
	private RabbitMqConfirmedPublisher $publisher;
	private string $exchangeName;
	private string $queueName;

	protected function setUp(): void
	{
		parent::setUp();

		$configuration = config('queue.connections.rabbitmq');

		$this->assertIsArray($configuration);

		$suffix = bin2hex(random_bytes(6));
		$this->exchangeName = 'test_outbox_confirmed_' . $suffix;
		$this->queueName = 'test_outbox_confirmed_queue_' . $suffix;

		$this->connection = new AMQPConnection($configuration);
		$this->connection->connect();
		$this->channel = new AMQPChannel($this->connection);

		$this->exchange = new AMQPExchange($this->channel);
		$this->exchange->setName($this->exchangeName);
		$this->exchange->setType(AMQP_EX_TYPE_TOPIC);
		$this->exchange->setFlags(AMQP_DURABLE);
		$this->exchange->declareExchange();

		$this->publisher = new RabbitMqConfirmedPublisher($configuration, $this->exchangeName, 5.0,);
	}

	protected function tearDown(): void
	{
		$this->queue?->delete();
		$this->exchange->delete();
		$this->channel->close();
		$this->connection->disconnect();

		parent::tearDown();
	}

	#[Test]
	public function it_should_publish_with_confirmation_mandatory_routing_and_persistent_delivery(): void
	{
		$this->queue = $this->declareQueue('orders.order_confirmed');
		$record = $this->record();
		$envelope = (new OutboxEnvelopeSerializer())->serialize($record);

		$this->publisher->publish($record, $envelope);

		$message = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $message);
		$this->assertSame($envelope, $message->getBody());
		$this->assertSame($record->eventId, $message->getMessageId());
		$this->assertSame('orders.order_confirmed', $message->getRoutingKey());
		$this->assertSame(AMQP_DELIVERY_MODE_PERSISTENT, $message->getDeliveryMode());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_reject_an_unroutable_message_returned_by_the_broker(): void
	{
		$record = $this->record();
		$envelope = (new OutboxEnvelopeSerializer())->serialize($record);

		try {
			$this->publisher->publish($record, $envelope);
			$this->fail('The publisher should reject an unroutable message.');
		} catch (OutboxPublicationException $exception) {
			$this->assertStringContainsString('unroutable', $exception->getMessage());
			$this->assertStringContainsString('orders.order_confirmed', $exception->getMessage());
		}
	}

	#[Test]
	public function it_should_reject_a_message_negatively_acknowledged_by_the_broker(): void
	{
		$this->queue = $this->declareQueue('orders.order_confirmed', [
			'x-max-length' => 1,
			'x-overflow' => 'reject-publish',
		]);

		$accepted = $this->record();
		$envelope = (new OutboxEnvelopeSerializer())->serialize($accepted);

		$this->publisher->publish($accepted, $envelope);

		$rejected = $this->record();

		try {
			$this->publisher->publish($rejected, (new OutboxEnvelopeSerializer())->serialize($rejected));
			$this->fail('The publisher should reject a negatively acknowledged message.');
		} catch (OutboxPublicationException $exception) {
			$this->assertStringContainsString('negatively acknowledged', $exception->getMessage());
		}

		$message = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $message);
		$this->assertSame($accepted->eventId, $message->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	/** @param array<string, int|string> $arguments */
	private function declareQueue(string $routingKey, array $arguments = []): AMQPQueue
	{
		$queue = new AMQPQueue($this->channel);
		$queue->setName($this->queueName);
		$queue->setFlags(AMQP_DURABLE);

		foreach ($arguments as $name => $value) {
			$queue->setArgument($name, $value);
		}

		$queue->declareQueue();
		$queue->bind($this->exchangeName, $routingKey);

		return $queue;
	}

	private function record(): OutboxRecord
	{
		return new OutboxRecord(
			id: SimpleUuid::random()->value(),
			producer: 'orders',
			eventId: SimpleUuid::random()->value(),
			eventName: 'orders.order_confirmed',
			eventVersion: '1',
			aggregateType: 'order',
			aggregateId: 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10',
			aggregateVersion: 7,
			correlationId: 'correlation-1',
			causationId: 'causation-1',
			payload: '{"orderId":"abc","total":19.90}',
			occurredAt: new DateTimeImmutable('2026-11-10 10:34:56', new DateTimeZone('UTC')),
			createdAt: new DateTimeImmutable('2026-11-10 10:35:01', new DateTimeZone('UTC')),
			publishedAt: null,
			attempts: 1,
			nextAttemptAt: null,
			lockedAt: new DateTimeImmutable('2026-11-10 10:35:01', new DateTimeZone('UTC')),
			claimToken: 'claim-token',
			publisherId: 'publisher-1',
			lastError: null,
			status: OutboxStatus::CLAIMED,
			failedAt: null,
		);
	}
}
