<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxRetention;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxRetentionTest extends UnitTestCase
{
	#[Test]
	public function it_should_use_the_explicit_command_line_retention_first(): void
	{
		$retention = OutboxRetention::resolve('3', 30);

		$this->assertSame(3, $retention->publishedRetentionDays);
	}

	#[Test]
	public function it_should_use_the_configured_retention_when_no_command_line_value_is_provided(): void
	{
		$retention = OutboxRetention::resolve(null, '14');

		$this->assertSame(14, $retention->publishedRetentionDays);
	}

	#[Test]
	public function it_should_use_seven_days_when_no_retention_is_configured(): void
	{
		$retention = OutboxRetention::resolve(null, null);

		$this->assertSame(OutboxRetention::DEFAULT_PUBLISHED_RETENTION_DAYS, $retention->publishedRetentionDays);
	}

	#[Test]
	public function it_should_calculate_the_cutoff_in_utc(): void
	{
		$retention = OutboxRetention::resolve(7, null);
		$now = new DateTimeImmutable('2026-11-11 12:34:56', new DateTimeZone('Europe/Madrid'));

		$cutoff = $retention->cutoff($now);

		$this->assertSame('2026-11-04 11:34:56', $cutoff->format('Y-m-d H:i:s'));
		$this->assertSame('UTC', $cutoff->getTimezone()->getName());
	}

	#[Test]
	public function it_should_reject_a_zero_retention_window(): void
	{
		$this->expectException(InvalidArgumentException::class);

		OutboxRetention::resolve(0, null);
	}

	#[Test]
	public function it_should_reject_a_negative_retention_window(): void
	{
		$this->expectException(InvalidArgumentException::class);

		OutboxRetention::resolve(-1, null);
	}

	#[Test]
	public function it_should_reject_a_non_numeric_retention_window(): void
	{
		$this->expectException(InvalidArgumentException::class);

		OutboxRetention::resolve('7.5', null);
	}
}
