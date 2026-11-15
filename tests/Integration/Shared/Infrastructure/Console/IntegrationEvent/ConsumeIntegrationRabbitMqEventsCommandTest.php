<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Console\IntegrationEvent;

use AMQPQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventQueueNameFormatter;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventRabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\IntegrationEventSubscriberLocator;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\Inbox\EphemeralInboxTable;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class ConsumeIntegrationRabbitMqEventsCommandTest extends InfrastructureTestCase
{
	private Connection $connection;
	private EphemeralInboxTable $tables;
	private RabbitMqConnection $rabbitMqConnection;
	private IntegrationEventQueueNameFormatter $formatter;
	private TestIntegrationEventSubscriber $subscriber;
	private string $exchangeName;

	protected function setUp(): void
	{
		parent::setUp();

		$suffix = bin2hex(random_bytes(5));
		$consumer = 'test.consumer_' . $suffix;

		$this->connection = DB::connection();
		$this->tables = new EphemeralInboxTable($this->connection);
		$this->tables->create();
		$this->tables->createBusinessTable();

		$this->exchangeName = 'integration_events_command_probe_' . $suffix;
		$this->formatter = new IntegrationEventQueueNameFormatter();
		$this->subscriber = new TestIntegrationEventSubscriber(
			$consumer,
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			function (IntegrationEventEnvelope $event): void {
				$this->connection->table($this->tables->businessTable)->insert([
					'id' => $event->eventId,
					'note' => 'consumed by command',
				]);
			}
		);

		config([
			'inbox.contexts' => ['test' => $this->tables->inboxTable],
			'inbox.exchange' => $this->exchangeName,
			'outbox.exchange' => $this->exchangeName,
		]);

		$this->rabbitMqConnection = $this->service(RabbitMqConnection::class);
		$this->app->instance(
			IntegrationEventSubscriberLocator::class,
			new IntegrationEventSubscriberLocator([$this->subscriber])
		);

		(new IntegrationEventRabbitMqConfigurer($this->rabbitMqConnection, $this->exchangeName))
			->configure($this->subscriber);
	}

	protected function tearDown(): void
	{
		foreach ($this->queueNames() as $queueName) {
			$this->rabbitMqConnection->queue($queueName)->delete();
		}

		foreach ($this->exchangeNames() as $exchangeName) {
			$this->rabbitMqConnection->exchange($exchangeName)->delete();
		}

		$this->tables->drop();

		parent::tearDown();
	}

	#[Test]
	public function it_should_process_only_the_requested_quantity_and_leave_the_rest_queued(): void
	{
		$first = $this->randomEventId();
		$second = $this->randomEventId();
		$third = $this->randomEventId();

		$this->publish($first);
		$this->publish($second);
		$this->publish($third);

		$this->artisan('manager:integration-events:rabbitmq:consume', [
			'queue' => $this->queueName(),
			'quantity' => 2,
		])
			->expectsOutput('Processed 2 integration event(s).')
			->assertSuccessful();

		$this->assertSame(2, $this->businessRows()->count());
		$this->assertSame(2, $this->inboxRows()->count());
		$this->assertSame(1, $this->queue()->declareQueue());

		$this->artisan('manager:integration-events:rabbitmq:consume', [
			'queue' => $this->queueName(),
			'quantity' => 1,
		])
			->expectsOutput('Processed 1 integration event(s).')
			->assertSuccessful();

		$this->assertSame(3, $this->businessRows()->count());
		$this->assertSame(3, $this->inboxRows()->count());
		$this->assertSame(0, $this->queue()->declareQueue());
	}

	#[Test]
	public function it_should_exit_successfully_when_the_queue_is_empty(): void
	{
		$this->artisan('manager:integration-events:rabbitmq:consume', [
			'queue' => $this->queueName(),
			'quantity' => 5,
		])
			->expectsOutput('Processed 0 integration event(s).')
			->assertSuccessful();

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->inboxRows()->count());
	}

	#[Test]
	public function it_should_fail_without_consuming_when_the_quantity_is_not_positive(): void
	{
		$this->publish($this->randomEventId());

		$this->artisan('manager:integration-events:rabbitmq:consume', [
			'queue' => $this->queueName(),
			'quantity' => 0,
		])
			->expectsOutput('The quantity must be a positive integer.')
			->assertFailed();

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->inboxRows()->count());
		$this->assertSame(1, $this->queue()->declareQueue());
	}

	#[Test]
	public function it_should_fail_without_consuming_when_the_quantity_is_not_numeric(): void
	{
		$this->publish($this->randomEventId());

		$this->artisan('manager:integration-events:rabbitmq:consume', [
			'queue' => $this->queueName(),
			'quantity' => 'not-a-number',
		])
			->expectsOutput('The quantity must be a positive integer.')
			->assertFailed();

		$this->assertSame(0, $this->businessRows()->count());
		$this->assertSame(0, $this->inboxRows()->count());
		$this->assertSame(1, $this->queue()->declareQueue());
	}

	private function publish(string $eventId): void
	{
		$this->rabbitMqConnection->exchange($this->exchangeName)->publish(
			IntegrationEventFixture::json(['eventId' => $eventId]),
			IntegrationEventFixture::EVENT_NAME,
			AMQP_NOPARAM,
			[
				'message_id' => 'message-' . $eventId,
				'content_type' => 'application/json',
				'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
			]
		);
	}

	private function businessRows(): Builder
	{
		return $this->connection->table($this->tables->businessTable);
	}

	private function inboxRows(): Builder
	{
		return $this->connection->table($this->tables->inboxTable);
	}

	private function randomEventId(): string
	{
		return SimpleUuid::random()->value();
	}

	private function queueName(): string
	{
		return $this->formatter->format($this->subscriber);
	}

	private function queue(): AMQPQueue
	{
		return $this->rabbitMqConnection->queue($this->queueName());
	}

	/**
	 * @return list<string>
	 */
	private function exchangeNames(): array
	{
		return [$this->exchangeName, 'retry-' . $this->exchangeName, 'dead_letter-' . $this->exchangeName];
	}

	/**
	 * @return list<string>
	 */
	private function queueNames(): array
	{
		return [
			$this->queueName(),
			$this->formatter->formatRetry($this->subscriber),
			$this->formatter->formatDeadLetter($this->subscriber),
		];
	}
}
