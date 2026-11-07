<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Infrastructure\Bus\Event\MySql;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Manager\Shared\Infrastructure\Bus\Event\DomainEventMapping;
use Manager\Shared\Infrastructure\Bus\Event\MySql\MySqlEloquentDomainEventsConsumer;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Shared\Infrastructure\PhpUnit\InfrastructureTestCase;

final class MySqlEloquentDomainEventsConsumerTest extends InfrastructureTestCase
{
	use RefreshDatabase;

	private MySqlEloquentDomainEventsConsumer $consumer;

	protected function setUp(): void
	{
		parent::setUp();

		$this->consumer = new MySqlEloquentDomainEventsConsumer(
			new DomainEventMapping([new MySqlConsumerTestDomainEventSubscriber(), ])
		);
	}

	#[Test]
	public function it_should_delete_all_successfully_consumed_events(): void
	{
		$firstEventId = $this->insertEvent();
		$secondEventId = $this->insertEvent();

		$consumedEventIds = [];

		$this->consumer->consume(
			subscribers: function (DomainEvent $event) use (&$consumedEventIds): void {
				$consumedEventIds[] = $event->eventId();
			},
			eventsToConsume: 2
		);

		self::assertEqualsCanonicalizing([$firstEventId, $secondEventId], $consumedEventIds);

		self::assertDatabaseCount('domain_events', 0);
	}

	#[Test]
	public function it_should_only_consume_the_requested_number_of_events(): void
	{
		$firstEventId = $this->insertEvent('2026-01-01 10:00:00');
		$secondEventId = $this->insertEvent('2026-01-01 10:01:00');
		$thirdEventId = $this->insertEvent('2026-01-01 10:02:00');

		$consumedEventIds = [];

		$this->consumer->consume(
			subscribers: function (DomainEvent $event) use (&$consumedEventIds): void {
				$consumedEventIds[] = $event->eventId();
			},
			eventsToConsume: 2
		);

		self::assertCount(2, $consumedEventIds);

		self::assertDatabaseMissing('domain_events', [
			'id' => $firstEventId,
		]);

		self::assertDatabaseMissing('domain_events', [
			'id' => $secondEventId,
		]);

		self::assertDatabaseHas('domain_events', [
			'id' => $thirdEventId,
		]);
	}

	#[Test]
	public function it_should_preserve_events_when_a_subscriber_fails(): void
	{
		$eventId = $this->insertEvent();

		try {
			$this->consumer->consume(
				subscribers: static function (DomainEvent $event): void {
					throw new RuntimeException('Subscriber failed.');
				},
				eventsToConsume: 1
			);

			self::fail('The subscriber exception should be propagated.');
		} catch (RuntimeException $exception) {
			self::assertSame('Subscriber failed.', $exception->getMessage());
		}

		self::assertDatabaseHas('domain_events', [
			'id' => $eventId,
		]);
	}

	private function insertEvent(string $occurredOn = '2026-01-01 10:00:00'): string
	{
		$eventId = (string) Str::uuid();

		DB::table('domain_events')->insert([
			'id' => $eventId,
			'aggregate_id' => (string) Str::uuid(),
			'name' => MySqlConsumerTestDomainEvent::eventName(),
			'body' => json_encode(['value' => 'test'], JSON_THROW_ON_ERROR),
			'occurred_on' => $occurredOn,
		]);

		return $eventId;
	}
}
