<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use AMQPException;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Domain\Bus\Event\EventBus;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventJsonSerializer;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentEventBus;

use function Lambdish\Phunctional\each;

final readonly class RabbitMqEventBus implements EventBus
{
	public function __construct(
		private RabbitMqConnection $connection,
		private string $exchangeName,
		private MySqlEloquentEventBus $failoverPublisher
	) {}

	public function publish(DomainEvent ...$events): void
	{
		each($this->publisher(), $events);
	}

	private function publisher(): callable
	{
		return function (DomainEvent $event): void {
			try {
				$this->publishEvent($event);
			} catch (AMQPException) {
				$this->failoverPublisher->publish($event);
			}
		};
	}

	private function publishEvent(DomainEvent $event): void
	{
		$body = DomainEventJsonSerializer::serialize($event);
		$routingKey = $event::eventName();
		$messageId = $event->eventId();

		$this->connection->exchange($this->exchangeName)->publish(
			$body,
			$routingKey,
			AMQP_NOPARAM,
			[
				'message_id' => $messageId,
				'content_type' => 'application/json',
				'content_encoding' => 'utf-8',
			]
		);
	}
}
