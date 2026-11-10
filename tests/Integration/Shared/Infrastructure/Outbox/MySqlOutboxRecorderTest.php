<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Manager\Shared\Application\Outbox\OutboxMessage;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Manager\Shared\Infrastructure\Outbox\MySqlOutboxRecorder;
use Manager\Shared\Infrastructure\Outbox\OutboxConnectionFactory;
use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use Manager\Shared\Infrastructure\Outbox\UnknownOutboxProducerException;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlOutboxRecorderTest extends InfrastructureTestCase
{
	private const AGGREGATE_ID = 'f3a2c9b8-6d4e-4b8b-a6e8-2f1c6d5e9a10';

	private Connection $connection;
	private MySqlOutboxRecorder $recorder;

	/** @var list<string> */
	private array $eventIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->eventIds = [];
		$this->connection = app(OutboxConnectionFactory::class)->connection();

		$this->recorder = new MySqlOutboxRecorder(
			$this->connection,
			new OutboxProducerValidator(['inventory', 'orders', 'catalog'])
		);
	}

	protected function tearDown(): void
	{
		if ($this->eventIds !== []) {
			$this->connection->table('outbox')->whereIn('event_id', $this->eventIds)->delete();
		}

		parent::tearDown();
	}

	#[Test]
	public function it_should_persist_a_pending_record_with_every_field(): void
	{
		$message = $this->message($this->preservedPayload());

		$this->recorder->append($message);

		$persistedEvent = $this->persistedEvent($message);

		$this->assertNotNull($persistedEvent);
		$this->assertTrue(Uuid::isValid($persistedEvent->id));
		$this->assertNotSame($message->eventId(), $persistedEvent->id);
		$this->assertSame('orders', $persistedEvent->producer);
		$this->assertSame('orders.order_confirmed', $persistedEvent->event_name);
		$this->assertSame('1', $persistedEvent->event_version);
		$this->assertSame('order', $persistedEvent->aggregate_type);
		$this->assertSame(self::AGGREGATE_ID, $persistedEvent->aggregate_id);
		$this->assertSame(7, (int) $persistedEvent->aggregate_version);
		$this->assertSame('correlation-1', $persistedEvent->correlation_id);
		$this->assertSame('causation-1', $persistedEvent->causation_id);
		$this->assertSame($this->preservedPayload(), $persistedEvent->payload);
		$this->assertSame('2026-11-10 10:34:56', $persistedEvent->occurred_at);
		$this->assertNotNull($persistedEvent->created_at);
		$this->assertNull($persistedEvent->published_at);
		$this->assertSame(0, (int) $persistedEvent->attempts);
		$this->assertNull($persistedEvent->next_attempt_at);
		$this->assertNull($persistedEvent->locked_at);
		$this->assertNull($persistedEvent->claim_token);
		$this->assertNull($persistedEvent->publisher_id);
		$this->assertNull($persistedEvent->last_error);
		$this->assertSame('pending', $persistedEvent->status);
		$this->assertNull($persistedEvent->failed_at);
	}

	#[Test]
	public function it_should_persist_absent_optional_fields_as_null(): void
	{
		$message = $this->message('{"sku":"A-1"}', aggregateVersion: null, correlationId: null, causationId: null);

		$this->recorder->append($message);

		$persistedEvent = $this->persistedEvent($message);

		$this->assertNotNull($persistedEvent);
		$this->assertNull($persistedEvent->aggregate_version);
		$this->assertNull($persistedEvent->correlation_id);
		$this->assertNull($persistedEvent->causation_id);
	}

	#[Test]
	public function it_should_preserve_the_exact_payload_text_without_reencoding_it(): void
	{
		$payloadJson = '{ "decimal" : 1.50, "unicode" : "Málaga 🐢", "nested" : { "b" : 2, "a" : 1 } }';
		$message = $this->message($payloadJson);

		$this->recorder->append($message);

		$persistedPayload = $this->connection->table('outbox')
			->where('event_id', $message->eventId())
			->value('payload');

		$this->assertSame($payloadJson, $persistedPayload);
	}

	#[Test]
	public function it_should_store_utc_wall_time_regardless_of_the_mysql_session_timezone(): void
	{
		$message = $this->message('{"sku":"A-1"}');
		$originalTimeZone = $this->connection->selectOne('SELECT @@session.time_zone AS time_zone')->time_zone;

		$this->connection->statement("SET time_zone = '+05:00'");

		try {
			$this->recorder->append($message);

			$persistedEvent = $this->persistedEvent($message);

			$this->assertNotNull($persistedEvent);
			$this->assertSame('2026-11-10 10:34:56', $persistedEvent->occurred_at);

			$createdAt = new DateTimeImmutable($persistedEvent->created_at, new DateTimeZone('UTC'));

			$this->assertLessThanOrEqual(60, abs($createdAt->getTimestamp() - time()));
		} finally {
			$this->connection->statement(sprintf("SET time_zone = '%s'", $originalTimeZone));
		}
	}

	#[Test]
	public function it_should_reject_an_unknown_producer_before_inserting(): void
	{
		$message = $this->message('{"sku":"A-1"}', producer: 'shipping');
		$caught = null;

		try {
			$this->recorder->append($message);
		} catch (UnknownOutboxProducerException $exception) {
			$caught = $exception;
		}

		$this->assertNotNull($caught);
		$this->assertSame(0, $this->connection->table('outbox')->where('event_id', $message->eventId())->count());
	}

	#[Test]
	public function it_should_reject_a_duplicate_event_id(): void
	{
		$message = $this->message('{"sku":"A-1"}');

		$this->recorder->append($message);

		$caught = null;

		try {
			$this->recorder->append($message);
		} catch (QueryException $exception) {
			$caught = $exception;
		}

		$this->assertNotNull($caught);
		$this->assertSame(1, $this->connection->table('outbox')->where('event_id', $message->eventId())->count());
	}

	private function message(
		string $payloadJson,
		string $producer = 'orders',
		?int $aggregateVersion = 7,
		?string $correlationId = 'correlation-1',
		?string $causationId = 'causation-1',
	): OutboxMessage {
		$eventId = SimpleUuid::random()->value();
		$this->eventIds[] = $eventId;

		return new OutboxMessage(
			$eventId,
			'orders.order_confirmed',
			'1',
			$producer,
			'order',
			self::AGGREGATE_ID,
			$payloadJson,
			new DateTimeImmutable('2026-11-10T12:34:56+02:00'),
			$aggregateVersion,
			$correlationId,
			$causationId,
		);
	}

	private function persistedEvent(OutboxMessage $message): ?object
	{
		return $this->connection->table('outbox')
			->where('event_id', $message->eventId())
			->first();
	}

	private function preservedPayload(): string
	{
		return '{"orderId":"abc","lines":[{"sku":"A-1","quantity":2}],"total":19.90,"paid":true,"notes":null}';
	}
}
