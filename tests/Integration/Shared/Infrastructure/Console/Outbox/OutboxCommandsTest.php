<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Console\Outbox;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Application\Outbox\OutboxRecorder;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class OutboxCommandsTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	private Connection $connection;
	private OutboxRecorder $recorder;
	private AMQPConnection $rabbitMqConnection;
	private AMQPChannel $rabbitMqChannel;
	private AMQPExchange $exchange;
	private AMQPQueue $queue;
	private string $exchangeName;

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->eventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();
		$this->recorder = app(OutboxRecorder::class);

		$configuration = config('queue.connections.rabbitmq');

		$this->assertIsArray($configuration);

		$suffix = bin2hex(random_bytes(6));
		$this->exchangeName = 'test_outbox_command_' . $suffix;

		$this->rabbitMqConnection = new AMQPConnection($configuration);
		$this->rabbitMqConnection->connect();
		$this->rabbitMqChannel = new AMQPChannel($this->rabbitMqConnection);

		$this->exchange = new AMQPExchange($this->rabbitMqChannel);
		$this->exchange->setName($this->exchangeName);
		$this->exchange->setType(AMQP_EX_TYPE_TOPIC);
		$this->exchange->setFlags(AMQP_DURABLE);
		$this->exchange->declareExchange();

		$this->queue = new AMQPQueue($this->rabbitMqChannel);
		$this->queue->setName('test_outbox_command_queue_' . $suffix);
		$this->queue->setFlags(AMQP_DURABLE);
		$this->queue->declareQueue();
		$this->queue->bind($this->exchangeName, '#');

		config(['outbox.exchange' => $this->exchangeName]);
		config(['outbox.published_retention_days' => 7]);
	}

	protected function tearDown(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		$this->queue->delete();
		$this->exchange->delete();
		$this->rabbitMqChannel->close();
		$this->rabbitMqConnection->disconnect();

		parent::tearDown();
	}

	#[Test]
	public function it_should_process_a_bounded_batch_and_exit_successfully(): void
	{
		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$this->assertSame(1, $this->publishedCount([$first, $second]));
		$this->assertSame(1, $this->pendingCount([$first, $second]));

		$firstMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $firstMessage);
		$this->assertContains($firstMessage->getMessageId(), [$first->eventId(), $second->eventId()]);

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$secondMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $secondMessage);
		$this->assertNotSame($firstMessage->getMessageId(), $secondMessage->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_use_the_configured_publish_batch_as_the_default_limit(): void
	{
		config(['outbox.publish_batch' => 1]);

		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish')
			->assertSuccessful();

		$this->assertSame(1, $this->publishedCount([$first, $second]));
		$this->assertSame(1, $this->pendingCount([$first, $second]));

		$this->artisan('manager:integration-events:outbox:publish')
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$firstMessage = $this->queue->get(AMQP_AUTOACK);
		$secondMessage = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $firstMessage);
		$this->assertInstanceOf(AMQPEnvelope::class, $secondMessage);
		$this->assertNotSame($firstMessage->getMessageId(), $secondMessage->getMessageId());
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_allow_the_limit_option_to_override_the_configured_publish_batch(): void
	{
		config(['outbox.publish_batch' => 1]);

		$first = $this->append('orders.order_confirmed');
		$second = $this->append('orders.order_cancelled');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 2])
			->assertSuccessful();

		$this->assertSame(2, $this->publishedCount([$first, $second]));
		$this->assertSame(0, $this->pendingCount([$first, $second]));

		$this->assertInstanceOf(AMQPEnvelope::class, $this->queue->get(AMQP_AUTOACK));
		$this->assertInstanceOf(AMQPEnvelope::class, $this->queue->get(AMQP_AUTOACK));
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_fail_without_processing_records_when_the_limit_is_not_positive(): void
	{
		$message = $this->append('orders.order_confirmed');

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 0])
			->assertFailed();

		$row = $this->connection->table('outbox')->where('event_id', $message->eventId())->first();

		$this->assertNotNull($row);
		$this->assertSame('pending', $row->status);
		$this->assertSame(0, (int) $row->attempts);
		$this->assertNull($this->queue->get(AMQP_AUTOACK));
	}

	#[Test]
	public function it_should_requeue_a_failed_record_and_publish_the_same_event_identity(): void
	{
		$message = $this->append('orders.order_confirmed');
		$lastError = 'OutboxPublicationException: RabbitMQ confirmation timed out.';

		$this->connection->table('outbox')->where('event_id', $message->eventId())->update([
			'status' => 'failed',
			'attempts' => 10,
			'failed_at' => '2026-11-10 11:55:00',
			'next_attempt_at' => null,
			'locked_at' => '2026-11-10 11:50:00',
			'claim_token' => 'stale-token',
			'publisher_id' => 'stale-publisher',
			'last_error' => $lastError,
		]);

		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => $message->eventId()])
			->assertSuccessful();

		$requeued = $this->row($message->eventId());

		$this->assertSame('pending', $requeued['status']);
		$this->assertSame(0, (int) $requeued['attempts']);
		$this->assertNull($requeued['failed_at']);
		$this->assertNull($requeued['next_attempt_at']);
		$this->assertNull($requeued['locked_at']);
		$this->assertNull($requeued['claim_token']);
		$this->assertNull($requeued['publisher_id']);
		$this->assertSame($lastError, $requeued['last_error']);

		$this->artisan('manager:integration-events:outbox:publish', ['--limit' => 1])
			->assertSuccessful();

		$envelope = $this->queue->get(AMQP_AUTOACK);

		$this->assertInstanceOf(AMQPEnvelope::class, $envelope);
		$this->assertSame($message->eventId(), $envelope->getMessageId());
		$this->assertSame($this->expectedEnvelope($message), $envelope->getBody());

		$published = $this->row($message->eventId());

		$this->assertSame('published', $published['status']);
		$this->assertSame(1, (int) $published['attempts']);
	}

	#[Test]
	public function it_should_refuse_to_requeue_a_record_that_is_not_failed(): void
	{
		$message = $this->append('orders.order_confirmed');

		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => $message->eventId()])
			->assertFailed();

		$row = $this->row($message->eventId());

		$this->assertSame('pending', $row['status']);
		$this->assertSame(0, (int) $row['attempts']);
	}

	#[Test]
	public function it_should_refuse_to_requeue_an_unknown_event_id(): void
	{
		$this->artisan('manager:integration-events:outbox:requeue', ['eventId' => SimpleUuid::random()->value()])
			->assertFailed();
	}

	#[Test]
	public function it_should_report_status_counts_and_recent_errors_without_exposing_payloads(): void
	{
		$this->connection->table('outbox')->delete();

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$oldestPending = $this->append('orders.pending');
		$retrying = $this->append('orders.retrying');
		$claimed = $this->append('orders.claimed');
		$published = $this->append('orders.published');
		$failed = $this->append('orders.failed', '{"orderId":"abc","secret":"PAYLOAD_SECRET_MARKER"}');

		$this->connection->table('outbox')->where('event_id', $oldestPending->eventId())->update([
			'created_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s'),
		]);
		$this->connection->table('outbox')->where('event_id', $retrying->eventId())->update([
			'next_attempt_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $claimed->eventId())->update([
			'status' => 'claimed',
			'locked_at' => $now->format('Y-m-d H:i:s'),
			'claim_token' => 'active-token',
			'publisher_id' => 'publisher-a',
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $published->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $failed->eventId())->update([
			'status' => 'failed',
			'failed_at' => $now->format('Y-m-d H:i:s'),
			'attempts' => 10,
			'last_error' => 'OutboxPublicationException: RabbitMQ confirmation timed out.',
		]);

		$this->artisan('manager:integration-events:outbox:status')
			->expectsOutputToContain('Pending: 2')
			->expectsOutputToContain('Claimed: 1')
			->expectsOutputToContain('Published: 1')
			->expectsOutputToContain('Failed: 1')
			->expectsOutputToContain('Retrying pending: 1')
			->expectsOutputToContain('Oldest pending age:')
			->expectsOutputToContain('Stale claims: 0')
			->expectsOutputToContain('Active publishers: 1')
			->expectsOutputToContain(sprintf(
				'event %s | producer orders | order %s | attempts 10 | failed at %s UTC | next retry never | error: OutboxPublicationException: RabbitMQ confirmation timed out.',
				$failed->eventId(),
				self::AGGREGATE_ID,
				$now->format('Y-m-d H:i:s')
			))
			->doesntExpectOutputToContain('PAYLOAD_SECRET_MARKER')
			->doesntExpectOutputToContain('"orderId"')
			->assertSuccessful();
	}

	#[Test]
	public function it_should_redact_secrets_from_recent_errors_in_the_status_output(): void
	{
		$this->connection->table('outbox')->delete();

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$failed = $this->append('orders.failed');

		$this->connection->table('outbox')->where('event_id', $failed->eventId())->update([
			'status' => 'failed',
			'failed_at' => $now->format('Y-m-d H:i:s'),
			'attempts' => 10,
			'last_error' => 'Connection amqp://guest:s3cr3t-hunter2@rabbitmq:5672 failed; '
				. 'password=s3cr3t-hunter2 token=s3cr3t-hunter2',
		]);

		$this->artisan('manager:integration-events:outbox:status')
			->expectsOutputToContain('[REDACTED]')
			->doesntExpectOutputToContain('s3cr3t-hunter2')
			->doesntExpectOutputToContain('guest')
			->assertSuccessful();
	}

	#[Test]
	public function it_should_purge_only_published_records_older_than_the_requested_window(): void
	{
		$this->connection->table('outbox')->delete();

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$oldPublished = $this->append('orders.old_published');
		$recentPublished = $this->append('orders.recent_published');
		$oldPending = $this->append('orders.old_pending');
		$oldClaimed = $this->append('orders.old_claimed');
		$oldFailed = $this->append('orders.old_failed');

		$this->connection->table('outbox')->where('event_id', $oldPublished->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-8 days')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $recentPublished->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $oldPending->eventId())->update([
			'created_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
		]);
		$this->connection->table('outbox')->where('event_id', $oldClaimed->eventId())->update([
			'status' => 'claimed',
			'created_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
			'locked_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
			'claim_token' => 'old-token',
			'publisher_id' => 'old-publisher',
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $oldFailed->eventId())->update([
			'status' => 'failed',
			'created_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
			'failed_at' => $now->modify('-10 days')->format('Y-m-d H:i:s'),
			'attempts' => 10,
			'last_error' => 'terminal',
		]);

		$this->artisan('manager:integration-events:outbox:purge', ['--published-older-than-days' => 7])
			->expectsOutputToContain('Purged 1 published outbox record(s)')
			->assertSuccessful();

		$this->assertNull($this->connection->table('outbox')->where('event_id', $oldPublished->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $recentPublished->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldPending->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldClaimed->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $oldFailed->eventId())->first());
	}

	#[Test]
	public function it_should_use_the_configured_retention_window_when_no_option_is_provided(): void
	{
		config(['outbox.published_retention_days' => 2]);

		$this->connection->table('outbox')->delete();

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$oldPublished = $this->append('orders.old_published');
		$recentPublished = $this->append('orders.recent_published');

		$this->connection->table('outbox')->where('event_id', $oldPublished->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);
		$this->connection->table('outbox')->where('event_id', $recentPublished->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);

		$this->artisan('manager:integration-events:outbox:purge')
			->expectsOutputToContain('retention: 2 day(s)')
			->assertSuccessful();

		$this->assertNull($this->connection->table('outbox')->where('event_id', $oldPublished->eventId())->first());
		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $recentPublished->eventId())->first());
	}

	#[Test]
	public function it_should_prefer_the_option_over_the_configured_retention_window(): void
	{
		config(['outbox.published_retention_days' => 30]);

		$this->connection->table('outbox')->delete();

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$published = $this->append('orders.published');

		$this->connection->table('outbox')->where('event_id', $published->eventId())->update([
			'status' => 'published',
			'published_at' => $now->modify('-8 days')->format('Y-m-d H:i:s'),
			'attempts' => 1,
		]);

		$this->artisan('manager:integration-events:outbox:purge', ['--published-older-than-days' => 7])
			->expectsOutputToContain('retention: 7 day(s)')
			->assertSuccessful();

		$this->assertNull($this->connection->table('outbox')->where('event_id', $published->eventId())->first());
	}

	#[Test]
	public function it_should_reject_a_non_positive_retention_window_without_deleting_records(): void
	{
		$this->connection->table('outbox')->delete();

		$published = $this->append('orders.published');

		$this->connection->table('outbox')->where('event_id', $published->eventId())->update([
			'status' => 'published',
			'published_at' => '2020-01-01 00:00:00',
			'attempts' => 1,
		]);

		$this->artisan('manager:integration-events:outbox:purge', ['--published-older-than-days' => 0])
			->expectsOutputToContain('retention must be a positive integer')
			->assertFailed();

		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $published->eventId())->first());
	}

	#[Test]
	public function it_should_reject_a_non_numeric_retention_window_without_deleting_records(): void
	{
		$this->connection->table('outbox')->delete();

		$published = $this->append('orders.published');

		$this->connection->table('outbox')->where('event_id', $published->eventId())->update([
			'status' => 'published',
			'published_at' => '2020-01-01 00:00:00',
			'attempts' => 1,
		]);

		$this->artisan('manager:integration-events:outbox:purge', ['--published-older-than-days' => 'abc'])
			->expectsOutputToContain('retention must be a positive integer')
			->assertFailed();

		$this->assertNotNull($this->connection->table('outbox')->where('event_id', $published->eventId())->first());
	}

	private function append(string $eventName, string $payload = '{"orderId":"abc"}'): OutboxMessage
	{
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		$message = new OutboxMessage(
			$eventId,
			$eventName,
			'1',
			'orders',
			'order',
			self::AGGREGATE_ID,
			$payload,
			new DateTimeImmutable('2026-11-10 10:34:56', new DateTimeZone('UTC')),
		);

		$this->recorder->append($message);

		return $message;
	}

	/** @return array<string, mixed> */
	private function row(string $eventId): array
	{
		$row = $this->connection->table('outbox')->where('event_id', $eventId)->first();

		$this->assertNotNull($row);

		/** @var array<string, mixed> $values */
		$values = (array) $row;

		return $values;
	}

	private function expectedEnvelope(OutboxMessage $message): string
	{
		return '{"eventId":"' . $message->eventId() . '",'
			. '"eventName":"' . $message->eventName() . '",'
			. '"eventVersion":"1",'
			. '"aggregateType":"order",'
			. '"aggregateId":"' . self::AGGREGATE_ID . '",'
			. '"occurredAt":"2026-11-10T10:34:56Z",'
			. '"payload":{"orderId":"abc"}}';
	}

	/** @param list<OutboxMessage> $messages */
	private function publishedCount(array $messages): int
	{
		return $this->connection->table('outbox')
			->whereIn('event_id', array_map(static fn (OutboxMessage $message): string => $message->eventId(), $messages))
			->where('status', 'published')
			->count();
	}

	/** @param list<OutboxMessage> $messages */
	private function pendingCount(array $messages): int
	{
		return $this->connection->table('outbox')
			->whereIn('event_id', array_map(static fn (OutboxMessage $message): string => $message->eventId(), $messages))
			->where('status', 'pending')
			->count();
	}
}
