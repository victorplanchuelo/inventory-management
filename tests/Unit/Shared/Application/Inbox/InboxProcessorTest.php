<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\Exception\InboxClaimNotVerified;
use Manager\Shared\Application\Inbox\InboxConsumerValidator;
use Manager\Shared\Application\Inbox\InboxOutcome;
use Manager\Shared\Application\Inbox\InboxProcessingResult;
use Manager\Shared\Application\Inbox\InboxProcessor;
use Manager\Shared\Application\Inbox\InboxRecord;
use Manager\Shared\Application\Inbox\InboxStore;
use Manager\Shared\Application\Inbox\InboxStoreFactory;
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

	private function subscriber(): TestIntegrationEventSubscriber
	{
		return new TestIntegrationEventSubscriber(
			'test.inbox_processor',
			[IntegrationEventFixture::EVENT_NAME => ['1']],
			static function (): void {}
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
