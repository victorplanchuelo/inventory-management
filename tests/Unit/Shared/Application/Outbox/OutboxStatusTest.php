<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Outbox;

use InvalidArgumentException;
use Manager\Shared\Application\Outbox\OutboxStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxStatusTest extends UnitTestCase
{
	#[Test]
	public function it_should_expose_the_persisted_status_values(): void
	{
		$this->assertSame('pending', OutboxStatus::PENDING->value);
		$this->assertSame('claimed', OutboxStatus::CLAIMED->value);
		$this->assertSame('published', OutboxStatus::PUBLISHED->value);
		$this->assertSame('failed', OutboxStatus::FAILED->value);
	}

	#[Test]
	#[DataProvider('allowedTransitions')]
	public function it_should_allow_expected_transitions(OutboxStatus $from, OutboxStatus $to): void
	{
		$this->assertTrue($from->canTransitionTo($to));
		$this->assertSame($to, $from->transitionTo($to));
	}

	public static function allowedTransitions(): iterable
	{
		yield 'pending to claimed' => [OutboxStatus::PENDING, OutboxStatus::CLAIMED];
		yield 'pending to failed on exhaustion' => [OutboxStatus::PENDING, OutboxStatus::FAILED];
		yield 'claimed to claimed on reclaim' => [OutboxStatus::CLAIMED, OutboxStatus::CLAIMED];
		yield 'claimed to pending on retry' => [OutboxStatus::CLAIMED, OutboxStatus::PENDING];
		yield 'claimed to published' => [OutboxStatus::CLAIMED, OutboxStatus::PUBLISHED];
		yield 'claimed to failed' => [OutboxStatus::CLAIMED, OutboxStatus::FAILED];
		yield 'failed to pending on requeue' => [OutboxStatus::FAILED, OutboxStatus::PENDING];
	}

	#[Test]
	#[DataProvider('disallowedTransitions')]
	public function it_should_reject_unexpected_transitions(OutboxStatus $from, OutboxStatus $to): void
	{
		$this->assertFalse($from->canTransitionTo($to));
	}

	public static function disallowedTransitions(): iterable
	{
		yield 'pending to published' => [OutboxStatus::PENDING, OutboxStatus::PUBLISHED];
		yield 'pending to pending' => [OutboxStatus::PENDING, OutboxStatus::PENDING];
		yield 'claimed to nothing published twice' => [OutboxStatus::PUBLISHED, OutboxStatus::CLAIMED];
		yield 'published to failed' => [OutboxStatus::PUBLISHED, OutboxStatus::FAILED];
		yield 'published to pending' => [OutboxStatus::PUBLISHED, OutboxStatus::PENDING];
		yield 'failed to claimed' => [OutboxStatus::FAILED, OutboxStatus::CLAIMED];
		yield 'failed to published' => [OutboxStatus::FAILED, OutboxStatus::PUBLISHED];
	}

	#[Test]
	public function it_should_throw_when_transitioning_to_a_disallowed_status(): void
	{
		$this->expectException(InvalidArgumentException::class);

		OutboxStatus::PUBLISHED->transitionTo(OutboxStatus::PENDING);
	}
}
