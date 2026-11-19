<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\Inbox\InboxStatusSnapshot;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxStatusSnapshotTest extends UnitTestCase
{
	#[Test]
	public function it_should_expose_the_reported_counts_and_timestamps(): void
	{
		$oldest = new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC'));
		$newest = new DateTimeImmutable('2026-11-18 09:00:00', new DateTimeZone('UTC'));

		$snapshot = new InboxStatusSnapshot(
			context: 'inventory',
			totalRecords: 10,
			processedRecords: 7,
			staleRecords: 2,
			recordsByConsumer: ['inventory.stock_projection' => 10],
			oldestProcessedAt: $oldest,
			newestProcessedAt: $newest,
			purgeableRecords: 3,
		);

		$this->assertSame('inventory', $snapshot->context);
		$this->assertSame(10, $snapshot->totalRecords);
		$this->assertSame(7, $snapshot->processedRecords);
		$this->assertSame(2, $snapshot->staleRecords);
		$this->assertSame(['inventory.stock_projection' => 10], $snapshot->recordsByConsumer);
		$this->assertSame($oldest, $snapshot->oldestProcessedAt);
		$this->assertSame($newest, $snapshot->newestProcessedAt);
		$this->assertSame(3, $snapshot->purgeableRecords);
	}

	#[Test]
	public function it_should_allow_an_unknown_purgeable_count_when_no_retention_is_configured(): void
	{
		$snapshot = new InboxStatusSnapshot(
			context: 'inventory',
			totalRecords: 0,
			processedRecords: 0,
			staleRecords: 0,
			recordsByConsumer: [],
			oldestProcessedAt: null,
			newestProcessedAt: null,
			purgeableRecords: null,
		);

		$this->assertNull($snapshot->purgeableRecords);
	}

	#[Test]
	public function it_should_reject_negative_counts(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new InboxStatusSnapshot(
			context: 'inventory',
			totalRecords: -1,
			processedRecords: 0,
			staleRecords: 0,
			recordsByConsumer: [],
			oldestProcessedAt: null,
			newestProcessedAt: null,
			purgeableRecords: null,
		);
	}

	#[Test]
	public function it_should_reject_negative_per_consumer_counts(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new InboxStatusSnapshot(
			context: 'inventory',
			totalRecords: 0,
			processedRecords: 0,
			staleRecords: 0,
			recordsByConsumer: ['inventory.stock_projection' => -1],
			oldestProcessedAt: null,
			newestProcessedAt: null,
			purgeableRecords: null,
		);
	}

	#[Test]
	public function it_should_reject_an_empty_context(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new InboxStatusSnapshot(
			context: '  ',
			totalRecords: 0,
			processedRecords: 0,
			staleRecords: 0,
			recordsByConsumer: [],
			oldestProcessedAt: null,
			newestProcessedAt: null,
			purgeableRecords: null,
		);
	}
}
