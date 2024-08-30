<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Mockery;

use Mockery\Matcher\MatcherInterface;
use Stringable;
use Tests\Shared\Infrastructure\PhpUnit\Constraint\ConstraintIsSimilar;

final readonly class MatcherIsSimilar implements MatcherInterface, Stringable
{
	private ConstraintIsSimilar $constraint;

	public function __construct(mixed $value, float $delta = 0.0)
	{
		$this->constraint = new ConstraintIsSimilar($value, $delta);
	}

	public function match(&$actual): bool
	{
		return $this->constraint->evaluate($actual, '', true);
	}

	public function __toString(): string
	{
		return 'Is similar';
	}
}
