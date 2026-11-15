<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPExchange;
use AMQPQueue;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\Exception\IntegrationEventPublicationFailed;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventFailurePublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfirmedIntegrationEventPublisher;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class RabbitMqConfirmedIntegrationEventPublisherTest extends InfrastructureTestCase
{
	private const ROUTING_KEY = 'integration.event.confirmed';
	/**
	 * A sub-microsecond timeout is deliberately shorter than a broker round trip. It forces the
	 * real AMQPChannel::waitForConfirm() path to reach its timeout instead of a mocked result.
	 */
	private const TIMEOUT_CONFIRMATION_SECONDS = 0.000000001;

	private RabbitMqConnection $connection;
	private IntegrationEventFailurePublisher $publisher;
	/** @var array<string, mixed> */
	private array $configuration;
	private string $exchangeName;
	private string $queueName;

	protected function setUp(): void
	{
		parent::setUp();

		$suffix = bin2hex(random_bytes(5));

		$configuration = config('queue.connections.rabbitmq');

		$this->assertIsArray($configuration);

		$this->configuration = $configuration;
		$this->connection = $this->service(RabbitMqConnection::class);
		$this->publisher = $this->service(IntegrationEventFailurePublisher::class);
		$this->exchangeName = 'integration_events_publisher_probe_' . $suffix;
		$this->queueName = 'integration_events_publisher_probe_queue_' . $suffix;

		$this->exchange()->setType(AMQP_EX_TYPE_TOPIC);
		$this->exchange()->setFlags(AMQP_DURABLE);
		$this->exchange()->declareExchange();

		$this->queue()->setFlags(AMQP_DURABLE);
		$this->queue()->declareQueue();
		$this->queue()->bind($this->exchangeName, self::ROUTING_KEY);
	}

	protected function tearDown(): void
	{
		$this->queue()->delete();
		$this->exchange()->delete();

		parent::tearDown();
	}

	#[Test]
	public function it_should_publish_a_message_only_after_the_broker_confirms_it(): void
	{
		$body = '{"event":"confirmed"}';

		$this->publisher->publish($this->exchangeName, self::ROUTING_KEY, $body, $this->properties());

		$this->eventually(fn () => $this->assertSame(1, $this->queue()->declareQueue()));

		$message = $this->queue()->get(AMQP_AUTOACK);

		$this->assertNotNull($message);
		$this->assertSame($body, $message->getBody());
		$this->assertSame('publisher-probe', $message->getMessageId());
	}

	#[Test]
	public function it_should_fail_and_reset_the_connection_when_the_broker_confirmation_times_out(): void
	{
		$publisher = new RabbitMqConfirmedIntegrationEventPublisher(
			$this->configuration,
			self::TIMEOUT_CONFIRMATION_SECONDS
		);
		$body = '{"event":"timeout"}';
		$caught = null;

		try {
			$publisher->publish($this->exchangeName, self::ROUTING_KEY, $body, $this->properties());
		} catch (IntegrationEventPublicationFailed $error) {
			$caught = $error;
		}

		$this->assertInstanceOf(IntegrationEventPublicationFailed::class, $caught);
		$this->assertSame('RabbitMQ could not confirm the integration-event publication.', $caught->getMessage());

		// The message reached the broker, proving the failure came from waiting for its confirmation.
		$this->eventually(fn () => $this->assertSame(1, $this->queue()->declareQueue()));
		$this->assertNotNull($this->queue()->get(AMQP_AUTOACK));

		$this->assertNull($this->privateProperty($publisher, 'connection'));
		$this->assertNull($this->privateProperty($publisher, 'channel'));
		$this->assertSame([], $this->privateProperty($publisher, 'exchanges'));
	}

	#[Test]
	public function it_should_fail_when_the_mandatory_message_is_unroutable_and_recover_for_the_next_publication(): void
	{
		try {
			$this->publisher->publish($this->exchangeName, 'unroutable', '{"event":"unroutable"}', $this->properties());
			$this->fail('An unroutable publication must fail.');
		} catch (IntegrationEventPublicationFailed $error) {
			$this->assertStringContainsString('NO_ROUTE', $error->getMessage());
			$this->assertStringContainsString($this->exchangeName, $error->getMessage());
		}

		$this->publisher->publish(
			$this->exchangeName,
			self::ROUTING_KEY,
			'{"event":"after-failure"}',
			$this->properties()
		);

		$this->eventually(fn () => $this->assertSame(1, $this->queue()->declareQueue()));

		$message = $this->queue()->get(AMQP_AUTOACK);

		$this->assertNotNull($message);
		$this->assertSame('{"event":"after-failure"}', $message->getBody());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function properties(): array
	{
		return [
			'message_id' => 'publisher-probe',
			'content_type' => 'application/json',
			'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
		];
	}

	private function privateProperty(RabbitMqConfirmedIntegrationEventPublisher $publisher, string $property): mixed
	{
		return (new ReflectionProperty($publisher, $property))->getValue($publisher);
	}

	private function exchange(): AMQPExchange
	{
		return $this->connection->exchange($this->exchangeName);
	}

	private function queue(): AMQPQueue
	{
		return $this->connection->queue($this->queueName);
	}
}
