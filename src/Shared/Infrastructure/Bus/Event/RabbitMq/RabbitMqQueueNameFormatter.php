<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Bus\Event\RabbitMq;

use Manager\Shared\Domain\Bus\Event\DomainEventSubscriber;
use Manager\Shared\Domain\Utils;

use function Lambdish\Phunctional\last;
use function Lambdish\Phunctional\map;

final class RabbitMqQueueNameFormatter
{
	public static function format(DomainEventSubscriber $subscriber): string
	{
		$subscriberClassPaths = explode('\\', str_replace('Manager', 'manager', $subscriber::class));

		$queueNameParts = [
			$subscriberClassPaths[0],
			$subscriberClassPaths[1],
			$subscriberClassPaths[2],
			last($subscriberClassPaths),
		];

		return implode('.', map(self::toSnakeCase(), $queueNameParts));
	}

	public static function formatRetry(DomainEventSubscriber $subscriber): string
	{
		$queueName = self::format($subscriber);

		return "retry.$queueName";
	}

	public static function formatDeadLetter(DomainEventSubscriber $subscriber): string
	{
		$queueName = self::format($subscriber);

		return "dead_letter.$queueName";
	}

	public static function shortFormat(DomainEventSubscriber $subscriber): string
	{
		$subscriberCamelCaseName = (string) last(explode('\\', $subscriber::class));

		return Utils::toSnakeCase($subscriberCamelCaseName);
	}

	private static function toSnakeCase(): callable
	{
		return static fn (string $text): string => Utils::toSnakeCase($text);
	}
}
