<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\PhpUnit;

use Tests\Shared\Domain\TestUtils;
use Tests\Shared\TestCase;
use Throwable;

abstract class InfrastructureTestCase extends TestCase
{
	protected function assertSimilar(mixed $expected, mixed $actual): void
	{
		TestUtils::assertSimilar($expected, $actual);
	}

	/** @param int<0, max> $timeToWaitOnErrorInSeconds
	 * @throws Throwable
	 */
	protected function eventually(
		callable $fn,
		int $totalRetries = 3,
		int $timeToWaitOnErrorInSeconds = 1,
		int $attempt = 0
	): void {
		try {
			$fn();
		} catch (Throwable $error) {
			if ($totalRetries === $attempt) {
				throw $error;
			}

			sleep($timeToWaitOnErrorInSeconds);
			$this->eventually($fn, $totalRetries, $timeToWaitOnErrorInSeconds, $attempt + 1);
		}
	}
}
