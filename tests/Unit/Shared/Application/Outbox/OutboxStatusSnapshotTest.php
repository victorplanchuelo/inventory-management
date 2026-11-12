<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxStatusSnapshot;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxStatusSnapshotTest extends UnitTestCase
{
	#[Test]
	public function it_should_return_no_age_when_no_pending_record_exists(): void
	{
		$snapshot = $this->snapshot(null);

		$this->assertNull($snapshot->oldestPendingAgeSeconds($this->now()));
	}

	#[Test]
	public function it_should_calculate_the_age_of_the_oldest_pending_record(): void
	{
		$createdAt = new DateTimeImmutable('2026-11-11 09:00:00', new DateTimeZone('UTC'));
		$snapshot = $this->snapshot($createdAt);

		$this->assertSame(3600, $snapshot->oldestPendingAgeSeconds($this->now()));
	}

	#[Test]
	public function it_should_not_return_a_negative_age_when_the_clock_is_behind_the_record(): void
	{
		$createdAt = new DateTimeImmutable('2026-11-11 10:10:00', new DateTimeZone('UTC'));
		$snapshot = $this->snapshot($createdAt);

		$this->assertSame(0, $snapshot->oldestPendingAgeSeconds($this->now()));
	}

	#[Test]
	public function it_should_reject_negative_counts(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new OutboxStatusSnapshot(
			pending: -1,
			claimed: 0,
			published: 0,
			failed: 0,
			retrying: 0,
			oldestPendingCreatedAt: null,
			staleClaims: 0,
			activePublishers: 0,
			lastPublishedAt: null,
			recentFailures: [],
		);
	}

	private function snapshot(?DateTimeImmutable $oldestPendingCreatedAt): OutboxStatusSnapshot
	{
		return new OutboxStatusSnapshot(
			pending: $oldestPendingCreatedAt === null ? 0 : 1,
			claimed: 0,
			published: 0,
			failed: 0,
			retrying: 0,
			oldestPendingCreatedAt: $oldestPendingCreatedAt,
			staleClaims: 0,
			activePublishers: 0,
			lastPublishedAt: null,
			recentFailures: [],
		);
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-11-11 10:00:00', new DateTimeZone('UTC'));
	}
}
