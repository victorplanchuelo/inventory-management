<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\AggregateVersionDecision;
use Manager\Shared\Application\Inbox\Exception\MissingAggregateVersion;
use Manager\Shared\Application\Inbox\MonotonicAggregateVersionPolicy;
use Manager\Shared\Application\Inbox\NoOrderingAggregateVersionPolicy;
use Manager\Shared\Application\Inbox\StrictSequentialAggregateVersionPolicy;
use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class AggregateVersionPolicyTest extends UnitTestCase
{
	/**
	 * @return iterable<string, array{?int, ?int}>
	 */
	public static function noOrderingCases(): iterable
	{
		yield 'without versions' => [null, null];
		yield 'without incoming version' => [null, 5];
		yield 'without baseline' => [3, null];
		yield 'newer version' => [4, 3];
		yield 'equal version' => [3, 3];
		yield 'older version' => [2, 3];
	}

	/**
	 * @return iterable<string, array{?int, ?int, AggregateVersionDecision}>
	 */
	public static function monotonicCases(): iterable
	{
		yield 'without versions' => [null, null, AggregateVersionDecision::PROCESS];
		yield 'without incoming version' => [null, 5, AggregateVersionDecision::PROCESS];
		yield 'without baseline' => [3, null, AggregateVersionDecision::PROCESS];
		yield 'newer version' => [4, 3, AggregateVersionDecision::PROCESS];
		yield 'equal version' => [3, 3, AggregateVersionDecision::STALE];
		yield 'older version' => [2, 3, AggregateVersionDecision::STALE];
	}

	/**
	 * @return iterable<string, array{int, ?int, AggregateVersionDecision}>
	 */
	public static function strictSequentialCases(): iterable
	{
		yield 'without baseline' => [3, null, AggregateVersionDecision::PROCESS];
		yield 'next version' => [4, 3, AggregateVersionDecision::PROCESS];
		yield 'equal version' => [3, 3, AggregateVersionDecision::STALE];
		yield 'older version' => [2, 3, AggregateVersionDecision::STALE];
		yield 'version gap' => [5, 3, AggregateVersionDecision::GAP];
	}

	#[Test]
	#[DataProvider('noOrderingCases')]
	public function it_should_always_process_without_ordering(?int $incomingVersion, ?int $lastAppliedVersion): void
	{
		$policy = new NoOrderingAggregateVersionPolicy();

		$this->assertSame(AggregateVersionDecision::PROCESS, $policy->decide($incomingVersion, $lastAppliedVersion));
	}

	#[Test]
	#[DataProvider('monotonicCases')]
	public function it_should_apply_the_monotonic_policy(
		?int $incomingVersion,
		?int $lastAppliedVersion,
		AggregateVersionDecision $expected
	): void {
		$policy = new MonotonicAggregateVersionPolicy();

		$this->assertSame($expected, $policy->decide($incomingVersion, $lastAppliedVersion));
	}

	#[Test]
	#[DataProvider('strictSequentialCases')]
	public function it_should_apply_the_strict_sequential_policy(
		int $incomingVersion,
		?int $lastAppliedVersion,
		AggregateVersionDecision $expected
	): void {
		$policy = new StrictSequentialAggregateVersionPolicy();

		$this->assertSame($expected, $policy->decide($incomingVersion, $lastAppliedVersion));
	}

	#[Test]
	public function it_should_reject_a_missing_version_under_strict_sequencing(): void
	{
		$policy = new StrictSequentialAggregateVersionPolicy();

		try {
			$policy->decide(null, 3);
			$this->fail('Strict sequencing must require an aggregate version.');
		} catch (MissingAggregateVersion $error) {
			$this->assertInstanceOf(PermanentIntegrationEventFailure::class, $error);
		}
	}

	#[Test]
	public function it_should_reject_a_missing_version_without_a_baseline_under_strict_sequencing(): void
	{
		$policy = new StrictSequentialAggregateVersionPolicy();

		$this->expectException(MissingAggregateVersion::class);

		$policy->decide(null, null);
	}
}
