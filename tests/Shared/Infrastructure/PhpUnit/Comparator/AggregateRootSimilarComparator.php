<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\PhpUnit\Comparator;

use Manager\Shared\Domain\Aggregate\AggregateRoot;
use ReflectionObject;
use SebastianBergmann\Comparator\Comparator;
use SebastianBergmann\Comparator\ComparisonFailure;
use SebastianBergmann\Exporter\Exporter;
use Tests\Shared\Domain\TestUtils;

final class AggregateRootSimilarComparator extends Comparator
{
	public function accepts($expected, $actual): bool
	{
		$aggregateRootClass = AggregateRoot::class;

		return $expected instanceof $aggregateRootClass && $actual instanceof $aggregateRootClass;
	}

	public function assertEquals($expected, $actual, $delta = 0.0, $canonicalize = false, $ignoreCase = false): void
	{
		$actualEntity = clone $actual;
		$actualEntity->pullDomainEvents();

		if (!$this->aggregateRootsAreSimilar($expected, $actualEntity)) {
			$exporter = new Exporter();

			throw new ComparisonFailure(
				$expected,
				$actual,
				$exporter->export($expected),
				$exporter->export($actual),
				'Failed asserting the aggregate roots are equal.'
			);
		}
	}

	private function aggregateRootsAreSimilar(AggregateRoot $expected, AggregateRoot $actual): bool
	{
		if (!$this->aggregateRootsAreTheSameClass($expected, $actual)) {
			return false;
		}

		return $this->aggregateRootPropertiesAreSimilar($expected, $actual);
	}

	private function aggregateRootsAreTheSameClass(AggregateRoot $expected, AggregateRoot $actual): bool
	{
		return $expected::class === $actual::class;
	}

	private function aggregateRootPropertiesAreSimilar(AggregateRoot $expected, AggregateRoot $actual): bool
	{
		$expectedReflected = new ReflectionObject($expected);
		$actualReflected = new ReflectionObject($actual);

		foreach ($expectedReflected->getProperties() as $expectedReflectedProperty) {
			$actualReflectedProperty = $actualReflected->getProperty($expectedReflectedProperty->getName());

			$expectedReflectedProperty->setAccessible(true);
			$actualReflectedProperty->setAccessible(true);

			$expectedProperty = $expectedReflectedProperty->getValue($expected);
			$actualProperty = $actualReflectedProperty->getValue($actual);

			if (!TestUtils::isSimilar($expectedProperty, $actualProperty)) {
				return false;
			}
		}

		return true;
	}
}
