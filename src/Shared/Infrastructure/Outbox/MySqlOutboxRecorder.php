<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Application\Outbox\OutboxStatus;
use Manager\Shared\Domain\ValueObject\SimpleUuid;

final readonly class MySqlOutboxRecorder implements OutboxRecorder
{
	/** UTC wall-clock format. The `outbox` date columns use MySQL DATETIME. */
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		private Connection $connection,
		private OutboxProducerValidator $producerValidator,
	) {}

	public function append(OutboxMessage $message): void
	{
		$this->producerValidator->assertIsRegistered($message->producer());

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$this->connection->table('outbox')->insert([
			'id' => SimpleUuid::random()->value(),
			'producer' => $message->producer(),
			'event_id' => $message->eventId(),
			'event_name' => $message->eventName(),
			'event_version' => $message->eventVersion(),
			'aggregate_type' => $message->aggregateType(),
			'aggregate_id' => $message->aggregateId(),
			'aggregate_version' => $message->aggregateVersion(),
			'correlation_id' => $message->correlationId(),
			'causation_id' => $message->causationId(),
			'payload' => $message->payloadJson(),
			'occurred_at' => $message->occurredOn()->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT),
			'created_at' => $now->format(self::DATE_FORMAT),
			'published_at' => null,
			'attempts' => 0,
			'next_attempt_at' => null,
			'locked_at' => null,
			'claim_token' => null,
			'publisher_id' => null,
			'last_error' => null,
			'status' => OutboxStatus::PENDING->value,
			'failed_at' => null,
		]);
	}
}
