<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPEnvelope;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class IntegrationEventRabbitMqTopologyTest extends InfrastructureTestCase
{
	private RabbitMqConnection $connection;
	private IntegrationEventQueueNameFormatter $formatter;
	private TestIntegrationEventSubscriber $subscriber;
	private string $exchangeName;

	protected function setUp(): void
	{
		parent::setUp();

		$suffix = bin2hex(random_bytes(5));

		$this->connection = $this->service(RabbitMqConnection::class);
		$this->formatter = new IntegrationEventQueueNameFormatter();
		$this->exchangeName = 'integration_events_probe_' . $suffix;
		$this->subscriber = new TestIntegrationEventSubscriber(
			'test.topology_' . $suffix,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			static function (): void {}
		);

		(new IntegrationEventRabbitMqConfigurer($this->connection, $this->exchangeName))
			->configure($this->subscriber);
	}

	protected function tearDown(): void
	{
		foreach ($this->queueNames() as $queueName) {
			$this->connection->queue($queueName)->delete();
		}

		foreach ($this->exchangeNames() as $exchangeName) {
			$this->connection->exchange($exchangeName)->delete();
		}

		parent::tearDown();
	}

	#[Test]
	public function it_should_declare_durable_topic_exchanges_and_durable_queues(): void
	{
		foreach ($this->exchangeNames() as $exchangeName) {
			$exchange = $this->connection->exchange($exchangeName);

			$this->assertSame('topic', $exchange->getType());
			$this->assertSame(AMQP_DURABLE, $exchange->getFlags() & AMQP_DURABLE);
		}

		foreach ($this->queueNames() as $queueName) {
			$queue = $this->connection->queue($queueName);

			$this->assertSame(AMQP_DURABLE, $queue->getFlags() & AMQP_DURABLE);
		}
	}

	#[Test]
	public function it_should_declare_the_retry_queue_with_the_fixed_one_second_dead_letter_configuration(): void
	{
		$arguments = $this->connection->queue($this->formatter->formatRetry($this->subscriber))->getArguments();

		$this->assertEquals(1000, $arguments['x-message-ttl']);
		$this->assertSame($this->exchangeName, $arguments['x-dead-letter-exchange']);
		$this->assertSame($this->formatter->format($this->subscriber), $arguments['x-dead-letter-routing-key']);
	}

	#[Test]
	public function it_should_configure_the_topology_idempotently(): void
	{
		$configurer = new IntegrationEventRabbitMqConfigurer($this->connection, $this->exchangeName);
		$configurer->configure($this->subscriber);

		$arguments = $this->connection->queue($this->formatter->formatRetry($this->subscriber))->getArguments();
		$this->assertEquals(1000, $arguments['x-message-ttl']);

		$this->publish($this->exchangeName, IntegrationEventFixture::EVENT_NAME, '{"event":"idempotent-configure"}');
		$this->assertQueueReceives($this->formatter->format($this->subscriber), '{"event":"idempotent-configure"}');
	}

	#[Test]
	public function it_should_route_an_event_to_the_main_queue_by_event_name(): void
	{
		$body = '{"event":"by-event-name"}';

		$this->publish($this->exchangeName, IntegrationEventFixture::EVENT_NAME, $body);

		$this->assertQueueReceives($this->formatter->format($this->subscriber), $body);
	}

	#[Test]
	public function it_should_route_an_event_to_the_main_queue_by_queue_name(): void
	{
		$body = '{"event":"by-queue-name"}';
		$queueName = $this->formatter->format($this->subscriber);

		$this->publish($this->exchangeName, $queueName, $body);

		$this->assertQueueReceives($queueName, $body);
	}

	#[Test]
	public function it_should_route_to_the_retry_queue_by_the_main_queue_name(): void
	{
		$body = '{"event":"retry-binding"}';
		$queueName = $this->formatter->format($this->subscriber);

		$this->publish('retry-' . $this->exchangeName, $queueName, $body);

		$this->assertQueueReceives($this->formatter->formatRetry($this->subscriber), $body);
	}

	#[Test]
	public function it_should_return_the_retry_message_to_the_main_queue_after_the_fixed_ttl(): void
	{
		$body = '{"event":"retry-return"}';
		$queueName = $this->formatter->format($this->subscriber);

		$this->publish('retry-' . $this->exchangeName, $queueName, $body);

		$startedAt = microtime(true);
		$this->assertQueueReceives($queueName, $body, 4);

		$this->assertGreaterThanOrEqual(0.9, microtime(true) - $startedAt);
	}

	#[Test]
	public function it_should_route_to_the_dead_letter_queue_by_the_main_queue_name(): void
	{
		$body = '{"event":"dead-letter-binding"}';
		$queueName = $this->formatter->format($this->subscriber);

		$this->publish('dead_letter-' . $this->exchangeName, $queueName, $body);

		$this->assertQueueReceives($this->formatter->formatDeadLetter($this->subscriber), $body);
	}

	private function publish(string $exchangeName, string $routingKey, string $body): void
	{
		$this->connection->exchange($exchangeName)->publish(
			$body,
			$routingKey,
			AMQP_NOPARAM,
			['delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT]
		);
	}

	private function assertQueueReceives(string $queueName, string $body, int $totalRetries = 3): void
	{
		$received = null;

		$this->eventually(function () use ($queueName, $body, &$received): void {
			$received = $this->connection->queue($queueName)->get(AMQP_AUTOACK);

			$this->assertInstanceOf(AMQPEnvelope::class, $received);
			$this->assertSame($body, $received->getBody());
		}, $totalRetries, 1);
	}

	/**
	 * @return list<string>
	 */
	private function exchangeNames(): array
	{
		return [$this->exchangeName, 'retry-' . $this->exchangeName, 'dead_letter-' . $this->exchangeName, ];
	}

	/**
	 * @return list<string>
	 */
	private function queueNames(): array
	{
		return [
			$this->formatter->format($this->subscriber),
			$this->formatter->formatRetry($this->subscriber),
			$this->formatter->formatDeadLetter($this->subscriber),
		];
	}
}
