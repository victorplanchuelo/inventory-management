<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\AggregateVersionDecision;
use Manager\Shared\Application\Inbox\AggregateVersionPolicy;
use Manager\Shared\Application\Inbox\Exception\InboxClaimNotVerified;
use Manager\Shared\Application\Inbox\Exception\MissingAggregatePredecessor;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\Inbox\InboxStoreFactory;
use Manager\Shared\Application\Inbox\NoOrderingAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\Exception\RetryableIntegrationEventFailure;
use Manager\Shared\Application\Transaction\TransactionalSession;
use Manager\Shared\Domain\ValueObject\SimpleUuid;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\IntegrationEvent\IntegrationEventFixture;
use Tests\Shared\Infrastructure\IntegrationEvent\TestIntegrationEventSubscriber;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxProcessorTest extends UnitTestCase
{
	#[Test]
	public function it_should_fail_when_the_duplicate_winner_does_not_exist(): void
	{
		$this->expectException(InboxClaimNotVerified::class);

		$this->processDuplicateKeyRace(null);
	}

	#[Test]
	public function it_should_fail_when_the_duplicate_winner_is_incomplete(): void
	{
		$this->expectException(InboxClaimNotVerified::class);

		$this->processDuplicateKeyRace($this->record(null, null));
	}

	#[Test]
	public function it_should_return_duplicate_only_when_the_winner_is_committed_and_complete(): void
	{
		$result = $this->processDuplicateKeyRace(
			$this->record(new DateTimeImmutable('now', new DateTimeZone('UTC')), InboxOutcome::PROCESSED)
		);

		$this->assertSame('duplicate', $result->value);
	}

	#[Test]
	public function it_should_detect_a_duplicate_before_applying_the_ordering_policy(): void
	{
		$policy = new class() implements AggregateVersionPolicy {
			public bool $called = false;

			public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
			{
				$this->called = true;

				return AggregateVersionDecision::PROCESS;
			}
		};
		$subscriberInvoked = false;
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnTrue();
		$store->shouldNotReceive('claim');
		$store->shouldNotReceive('lastProcessedAggregateVersion');
		$store->shouldNotReceive('markProcessed');
		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);
		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldNotReceive('execute');

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		$result = $processor->process(
			IntegrationEventFixture::envelope(),
			$this->subscriber(function () use (&$subscriberInvoked): void {
				$subscriberInvoked = true;
			}, $policy)
		);

		$this->assertSame(InboxProcessingResult::DUPLICATE, $result);
		$this->assertFalse($policy->called);
		$this->assertFalse($subscriberInvoked);
	}

	#[Test]
	public function it_should_skip_the_last_applied_version_lookup_for_a_no_ordering_policy(): void
	{
		$subscriberInvoked = false;
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnFalse();
		$store->shouldReceive('claim')->once()->andReturnTrue();
		$store->shouldNotReceive('lastProcessedAggregateVersion');
		$store->shouldReceive('markProcessed')
			->once()
			->withArgs(
				static fn ($consumer, $eventId, $outcome, $processedAt): bool => $outcome === InboxOutcome::PROCESSED
			);
		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);
		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldReceive('execute')->once()->andReturnUsing(
			static fn (callable $operation): mixed => $operation()
		);

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		$result = $processor->process(
			IntegrationEventFixture::envelope(),
			$this->subscriber(
				function () use (&$subscriberInvoked): void {
					$subscriberInvoked = true;
				},
				new NoOrderingAggregateVersionPolicy()
			)
		);

		$this->assertSame(InboxProcessingResult::PROCESSED, $result);
		$this->assertTrue($subscriberInvoked);
	}

	#[Test]
	public function it_should_apply_the_policy_after_claiming_and_before_invoking_the_subscriber(): void
	{
		$subscriberInvoked = false;
		$policy = new class() implements AggregateVersionPolicy {
			public ?int $incomingVersion = null;
			public ?int $lastAppliedVersion = null;

			public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
			{
				$this->incomingVersion = $incomingVersion;
				$this->lastAppliedVersion = $lastAppliedVersion;

				return AggregateVersionDecision::PROCESS;
			}
		};
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnFalse();
		$store->shouldReceive('claim')->once()->andReturnTrue();
		$store->shouldReceive('lastProcessedAggregateVersion')
			->once()
			->with('test.inbox_processor', 'stock_item', IntegrationEventFixture::AGGREGATE_ID)
			->andReturn(2);
		$store->shouldReceive('markProcessed')
			->once()
			->withArgs(
				static fn ($consumer, $eventId, $outcome, $processedAt): bool => $outcome === InboxOutcome::PROCESSED
			);
		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);
		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldReceive('execute')->once()->andReturnUsing(
			static fn (callable $operation): mixed => $operation()
		);

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		$result = $processor->process(
			IntegrationEventFixture::envelope(),
			$this->subscriber(function () use (&$subscriberInvoked): void {
				$subscriberInvoked = true;
			}, $policy)
		);

		$this->assertSame(InboxProcessingResult::PROCESSED, $result);
		$this->assertSame(3, $policy->incomingVersion);
		$this->assertSame(2, $policy->lastAppliedVersion);
		$this->assertTrue($subscriberInvoked);
	}

	#[Test]
	public function it_should_mark_a_stale_claim_without_invoking_the_subscriber(): void
	{
		$subscriberInvoked = false;
		$policy = new class() implements AggregateVersionPolicy {
			public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
			{
				return AggregateVersionDecision::STALE;
			}
		};
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnFalse();
		$store->shouldReceive('claim')->once()->andReturnTrue();
		$store->shouldReceive('lastProcessedAggregateVersion')->once()->andReturn(3);
		$store->shouldReceive('markProcessed')
			->once()
			->withArgs(static fn ($consumer, $eventId, $outcome, $processedAt): bool => $outcome === InboxOutcome::STALE);
		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);
		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldReceive('execute')->once()->andReturnUsing(
			static fn (callable $operation): mixed => $operation()
		);

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		$result = $processor->process(
			IntegrationEventFixture::envelope(),
			$this->subscriber(function () use (&$subscriberInvoked): void {
				$subscriberInvoked = true;
			}, $policy)
		);

		$this->assertSame(InboxProcessingResult::STALE, $result);
		$this->assertFalse($subscriberInvoked);
	}

	#[Test]
	public function it_should_throw_a_retryable_failure_for_a_version_gap(): void
	{
		$subscriberInvoked = false;
		$policy = new class() implements AggregateVersionPolicy {
			public function decide(?int $incomingVersion, ?int $lastAppliedVersion): AggregateVersionDecision
			{
				return AggregateVersionDecision::GAP;
			}
		};
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnFalse();
		$store->shouldReceive('claim')->once()->andReturnTrue();
		$store->shouldReceive('lastProcessedAggregateVersion')->once()->andReturn(2);
		$store->shouldNotReceive('markProcessed');
		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);
		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldReceive('execute')->once()->andReturnUsing(
			static fn (callable $operation): mixed => $operation()
		);

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		try {
			$processor->process(
				IntegrationEventFixture::envelope(),
				$this->subscriber(function () use (&$subscriberInvoked): void {
					$subscriberInvoked = true;
				}, $policy)
			);
			$this->fail('A version gap must fail with a retryable missing-predecessor error.');
		} catch (MissingAggregatePredecessor $error) {
			$this->assertInstanceOf(RetryableIntegrationEventFailure::class, $error);
			$this->assertStringContainsString('<3>', $error->getMessage());
			$this->assertStringContainsString('<2>', $error->getMessage());
		}

		$this->assertFalse($subscriberInvoked);
	}

	private function processDuplicateKeyRace(?InboxRecord $winner): InboxProcessingResult
	{
		$store = Mockery::mock(InboxStore::class);
		$store->shouldReceive('hasHandled')->once()->andReturnFalse();
		$store->shouldReceive('claim')->once()->andReturnFalse();
		$store->shouldReceive('find')->once()->andReturn($winner);

		$factory = Mockery::mock(InboxStoreFactory::class);
		$factory->shouldReceive('forConsumer')->once()->andReturn($store);

		$session = Mockery::mock(TransactionalSession::class);
		$session->shouldReceive('execute')->once()->andReturnUsing(
			static fn (callable $operation): mixed => $operation()
		);

		$processor = new InboxProcessor(
			$session,
			$factory,
			new InboxConsumerValidator(['test' => 'inbox_test'])
		);

		return $processor->process(IntegrationEventFixture::envelope(), $this->subscriber());
	}

	private function subscriber(
		?callable $handler = null,
		?AggregateVersionPolicy $orderingPolicy = null
	): TestIntegrationEventSubscriber {
		return new TestIntegrationEventSubscriber(
			'test.inbox_processor',
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			$handler ?? static function (): void {},
			$orderingPolicy
		);
	}

	private function record(?DateTimeImmutable $processedAt, ?InboxOutcome $outcome): InboxRecord
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		return new InboxRecord(
			SimpleUuid::random()->value(),
			'test.inbox_processor',
			IntegrationEventFixture::EVENT_ID,
			IntegrationEventFixture::EVENT_NAME,
			IntegrationEventFixture::EVENT_VERSION,
			'stock_item',
			IntegrationEventFixture::AGGREGATE_ID,
			3,
			new DateTimeImmutable(IntegrationEventFixture::OCCURRED_AT, new DateTimeZone('UTC')),
			$processedAt,
			$outcome,
			'correlation-1',
			'causation-1',
			$now,
		);
	}
}
