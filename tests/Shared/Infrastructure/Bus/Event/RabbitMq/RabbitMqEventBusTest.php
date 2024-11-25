<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonDeserializer;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBus;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConfigurer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqConnection;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqDomainEventsConsumer;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqEventBus;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\RabbitMqQueueNameFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Api\User\Domain\ObjectMother\UserCreatedDomainEventMother;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;
use RuntimeException;
use Throwable;

final class RabbitMqEventBusTest extends InfrastructureTestCase
{
	private mixed $connection;
	private string $exchangeName;
	private RabbitMqConfigurer $configurer;
	private RabbitMqEventBus $publisher;
	private RabbitMqDomainEventsConsumer $consumer;
	private TestAllWorksOnRabbitMqEventsPublished $fakeSubscriber;
	private bool $consumerHasBeenExecuted;

	protected function setUp(): void
	{
		parent::setUp();



		$this->connection = $this->service(RabbitMqConnection::class);

		$this->exchangeName = 'test_domain_events';
		$this->configurer = new RabbitMqConfigurer($this->connection);
		$this->publisher = new RabbitMqEventBus(
			$this->connection,
			$this->exchangeName,
			new MySqlEloquentEventBus()
		);
		$this->consumer = new RabbitMqDomainEventsConsumer(
			$this->connection,
			new DomainEventJsonDeserializer(
                $this->service(DomainEventMapping::class),
            ),
			$this->exchangeName,
            1
		);
		$this->fakeSubscriber = new TestAllWorksOnRabbitMqEventsPublished();
		$this->consumerHasBeenExecuted = false;

		$this->cleanEnvironment($this->connection);
	}

    #[Test] public function it_should_publish_and_consume_domain_events_from_rabbitmq(): void
	{
		$domainEvent = UserCreatedDomainEventMother::create();

		$this->configurer->configure($this->exchangeName, $this->fakeSubscriber);

		$this->publisher->publish($domainEvent);

		$this->consumer->consume(
			$this->assertConsumer($domainEvent),
			RabbitMqQueueNameFormatter::format($this->fakeSubscriber)
		);

		$this->assertTrue($this->consumerHasBeenExecuted);
	}

    //Not working until create another DomainEvent that are not on DomainEventJsonDeserializer -> DomainEventMapping
//    #[Test] public function it_should_throw_an_exception_consuming_non_existing_domain_events(): void
//	{
//		$this->expectException(RuntimeException::class);
//
//		$domainEvent = UserCreatedDomainEventMother::create();
//
//		$this->configurer->configure($this->exchangeName, $this->fakeSubscriber);
//
//		$this->publisher->publish($domainEvent);
//
//        $this->simulateErrorConsuming();
//
//		$this->assertFalse($this->consumerHasBeenExecuted);
//
////        $this->consumer->consume(
////            $this->assertConsumer($domainEvent),
////            RabbitMqQueueNameFormatter::format($this->fakeSubscriber)
////        );
////
////        $this->assertTrue($this->consumerHasBeenExecuted);
//	}

    #[Test] public function it_should_retry_failed_domain_events(): void
	{
		$domainEvent = UserCreatedDomainEventMother::create();

		$this->configurer->configure($this->exchangeName, $this->fakeSubscriber);

		$this->publisher->publish($domainEvent);

		$this->simulateErrorConsuming();

		sleep(1);

		$this->consumer->consume(
			$this->assertConsumer($domainEvent),
			RabbitMqQueueNameFormatter::format($this->fakeSubscriber)
		);

		$this->assertTrue($this->consumerHasBeenExecuted);
	}

    #[Test] public function it_should_send_events_to_dead_letter_after_retry_failed_domain_events(): void
	{
		$domainEvent = UserCreatedDomainEventMother::create();

		$this->configurer->configure($this->exchangeName, $this->fakeSubscriber);

		$this->publisher->publish($domainEvent);

		$this->simulateErrorConsuming();

		sleep(1);

		$this->simulateErrorConsuming();

		$this->assertDeadLetterContainsEvent(1);
	}

	private function assertConsumer(DomainEvent ...$expectedDomainEvents): callable
	{
		return function (DomainEvent $domainEvent) use ($expectedDomainEvents): void {
			$this->assertContainsEquals($domainEvent, $expectedDomainEvents);

			$this->consumerHasBeenExecuted = true;
		};
	}

	private function failingConsumer(): callable
	{
		return static function (DomainEvent $domainEvent): never {
			throw new RuntimeException('To test');
		};
	}

	private function simulateErrorConsuming(): void
	{
		try {
			$this->consumer->consume($this->failingConsumer(), RabbitMqQueueNameFormatter::format($this->fakeSubscriber));
		} catch (Throwable $error) {
			$this->assertInstanceOf(RuntimeException::class, $error);
		}
	}

	private function cleanEnvironment(RabbitMqConnection $connection): void
	{
		$connection->queue(RabbitMqQueueNameFormatter::format($this->fakeSubscriber))->delete();
		$connection->queue(RabbitMqQueueNameFormatter::formatRetry($this->fakeSubscriber))->delete();
		$connection->queue(RabbitMqQueueNameFormatter::formatDeadLetter($this->fakeSubscriber))->delete();
	}

	private function assertDeadLetterContainsEvent(int $expectedNumberOfEvents): void
	{
		$totalEventsInDeadLetter = 0;

		while ($this->connection->queue(RabbitMqQueueNameFormatter::formatDeadLetter($this->fakeSubscriber))->get(
			AMQP_AUTOACK
		)) {
			$totalEventsInDeadLetter++;
		}

		$this->assertSame($expectedNumberOfEvents, $totalEventsInDeadLetter);
	}
}
