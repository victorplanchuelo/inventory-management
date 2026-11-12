<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Manager\Shared\Infrastructure\Outbox\OutboxRetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxRetryPolicyTest extends UnitTestCase
{
	#[Test]
	public function it_should_compute_the_exponential_backoff_sequence(): void
	{
		$policy = new OutboxRetryPolicy();
		$failedAt = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC'));
		$expectedDelays = [
			1 => 5,
			2 => 10,
			3 => 20,
			4 => 40,
			5 => 80,
			6 => 160,
			7 => 320,
			8 => 600,
			9 => 600,
		];

		foreach ($expectedDelays as $attempts => $expectedDelay) {
			$nextAttemptAt = $policy->nextAttemptAt($failedAt, $attempts);

			$this->assertSame(
				$expectedDelay,
				$nextAttemptAt->getTimestamp() - $failedAt->getTimestamp(),
				sprintf('Unexpected backoff after attempt %d.', $attempts)
			);
		}
	}

	#[Test]
	public function it_should_treat_the_tenth_attempt_as_terminal(): void
	{
		$policy = new OutboxRetryPolicy();

		$this->assertFalse($policy->isTerminal(9));
		$this->assertTrue($policy->isTerminal(10));
		$this->assertTrue($policy->isTerminal(11));
	}

	#[Test]
	public function it_should_refuse_to_schedule_an_attempt_beyond_the_configured_limit(): void
	{
		$policy = new OutboxRetryPolicy();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('attempt 11 of 10');

		$policy->nextAttemptAt(new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC')), 10);
	}

	#[Test]
	public function it_should_use_the_configured_attempt_limit_and_backoff(): void
	{
		$policy = new OutboxRetryPolicy(3, 2, 3, 10);
		$failedAt = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC'));

		$this->assertFalse($policy->isTerminal(2));
		$this->assertTrue($policy->isTerminal(3));
		$this->assertSame(2, $policy->nextAttemptAt($failedAt, 1)->getTimestamp() - $failedAt->getTimestamp());
		$this->assertSame(6, $policy->nextAttemptAt($failedAt, 2)->getTimestamp() - $failedAt->getTimestamp());
	}

	#[Test]
	public function it_should_reject_an_invalid_retry_configuration(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new OutboxRetryPolicy(0);
	}

	#[Test]
	public function it_should_reject_a_non_positive_attempt_count(): void
	{
		$policy = new OutboxRetryPolicy();

		$this->expectException(InvalidArgumentException::class);

		$policy->isTerminal(0);
	}
}
