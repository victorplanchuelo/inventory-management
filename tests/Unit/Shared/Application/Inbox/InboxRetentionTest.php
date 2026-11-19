<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use DateTimeImmutable;
use DateTimeZone;
use Manager\Shared\Application\Inbox\Exception\InvalidInboxRetention;
use Manager\Shared\Application\Inbox\InboxRetention;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class InboxRetentionTest extends UnitTestCase
{
	#[Test]
	public function it_should_use_the_explicit_command_line_retention_first(): void
	{
		$retention = InboxRetention::resolve('3', 30);

		$this->assertNotNull($retention);
		$this->assertSame(3, $retention->retentionDays);
	}

	#[Test]
	public function it_should_use_the_configured_retention_when_no_command_line_value_is_provided(): void
	{
		$retention = InboxRetention::resolve(null, '14');

		$this->assertNotNull($retention);
		$this->assertSame(14, $retention->retentionDays);
	}

	#[Test]
	public function it_should_return_null_when_no_retention_is_configured(): void
	{
		$this->assertNull(InboxRetention::resolve(null, null));
	}

	#[Test]
	public function it_should_treat_empty_values_as_not_configured(): void
	{
		$this->assertNull(InboxRetention::resolve('', '  '));
	}

	#[Test]
	public function it_should_calculate_the_cutoff_in_utc(): void
	{
		$retention = InboxRetention::resolve(7, null);

		$this->assertNotNull($retention);

		$now = new DateTimeImmutable('2026-11-18 12:34:56', new DateTimeZone('Europe/Madrid'));

		$cutoff = $retention->cutoff($now);

		$this->assertSame('2026-11-11 11:34:56', $cutoff->format('Y-m-d H:i:s'));
		$this->assertSame('UTC', $cutoff->getTimezone()->getName());
	}

	#[Test]
	public function it_should_reject_a_zero_retention_window(): void
	{
		$this->expectException(InvalidInboxRetention::class);

		InboxRetention::resolve(0, null);
	}

	#[Test]
	public function it_should_reject_a_negative_retention_window(): void
	{
		$this->expectException(InvalidInboxRetention::class);

		InboxRetention::resolve(-1, null);
	}

	#[Test]
	public function it_should_reject_a_non_numeric_retention_window(): void
	{
		$this->expectException(InvalidInboxRetention::class);

		InboxRetention::resolve('abc', null);
	}

	#[Test]
	public function it_should_reject_a_fractional_retention_window(): void
	{
		$this->expectException(InvalidInboxRetention::class);

		InboxRetention::resolve('7.5', null);
	}

	#[Test]
	public function it_should_not_fall_back_to_the_configuration_when_the_command_line_value_is_invalid(): void
	{
		$this->expectException(InvalidInboxRetention::class);

		InboxRetention::resolve('0', 7);
	}
}
