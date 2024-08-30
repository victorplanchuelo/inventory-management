<?php

declare(strict_types=1);

namespace Tests\Shared\Domain;

use Tests\Shared\Infrastructure\Mockery\MatcherIsSimilar;
use Tests\Shared\Infrastructure\PhpUnit\Constraint\ConstraintIsSimilar;

final class TestUtils
{
	public static function isSimilar(mixed $expected, mixed $actual): bool
	{
		$constraint = new ConstraintIsSimilar($expected);

		return $constraint->evaluate($actual, '', true);
	}

	public static function assertSimilar(mixed $expected, mixed $actual): void
	{
		$constraint = new ConstraintIsSimilar($expected);
		$constraint->evaluate($actual);
	}

	public static function similarTo(mixed $value, float $delta = 0.0): MatcherIsSimilar
	{
		return new MatcherIsSimilar($value, $delta);
	}
}
