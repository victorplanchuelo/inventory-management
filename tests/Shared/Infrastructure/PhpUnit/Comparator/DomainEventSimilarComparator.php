<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\PhpUnit\Comparator;

use Manager\Shared\Domain\Bus\Event\DomainEvent;
use Tests\Shared\Domain\TestUtils;
use ReflectionObject;
use SebastianBergmann\Comparator\Comparator;
use SebastianBergmann\Comparator\ComparisonFailure;
use SebastianBergmann\Exporter\Exporter;

final class DomainEventSimilarComparator extends Comparator
{
	private static array $ignoredAttributes = ['eventId', 'occurredOn'];

	public function accepts($expected, $actual): bool
	{
		$domainEventRootClass = DomainEvent::class;

		return $expected instanceof $domainEventRootClass && $actual instanceof $domainEventRootClass;
	}

	public function assertEquals($expected, $actual, $delta = 0.0, $canonicalize = false, $ignoreCase = false): void
	{
		if (!$this->areSimilar($expected, $actual)) {
			$exporter = new Exporter();

            throw new ComparisonFailure(
				$expected,
				$actual,
				$exporter->export($expected),
				$exporter->export($actual),
				'Failed asserting the events are equal.'
			);
		}
	}

	private function areSimilar(DomainEvent $expected, DomainEvent $actual): bool
	{
		if (!$this->areTheSameClass($expected, $actual)) {
			return false;
		}

		return $this->propertiesAreSimilar($expected, $actual);
	}

	private function areTheSameClass(DomainEvent $expected, DomainEvent $actual): bool
	{
		return $expected::class === $actual::class;
	}

	private function propertiesAreSimilar(DomainEvent $expected, DomainEvent $actual): bool
	{
		$expectedReflected = new ReflectionObject($expected);
		$actualReflected = new ReflectionObject($actual);

		foreach ($expectedReflected->getProperties() as $expectedReflectedProperty) {
			if (!in_array($expectedReflectedProperty->getName(), self::$ignoredAttributes, false)) {
				$actualReflectedProperty = $actualReflected->getProperty($expectedReflectedProperty->getName());

				$expectedReflectedProperty->setAccessible(true);
				$actualReflectedProperty->setAccessible(true);

				$expectedProperty = $expectedReflectedProperty->getValue($expected);
				$actualProperty = $actualReflectedProperty->getValue($actual);

				if (!TestUtils::isSimilar($expectedProperty, $actualProperty)) {
					return false;
				}
			}
		}

		return true;
	}
}
