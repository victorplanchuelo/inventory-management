<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Inbox;

use Manager\Shared\Application\Inbox\Exception\InvalidInboxConsumer;

final readonly class InboxConsumerValidator
{
	private const MAX_CONSUMER_NAME_LENGTH = 128;
	private const CONSUMER_NAME_PATTERN = '/^([A-Za-z0-9_-]+)\.([A-Za-z0-9][A-Za-z0-9_.-]*)$/';

	/**
	 * @param array<string, string> $contexts
	 */
	public function __construct(private array $contexts) {}

	public function assertIsValid(string $consumerName): void
	{
		if (mb_strlen($consumerName, 'UTF-8') > self::MAX_CONSUMER_NAME_LENGTH) {
			throw InvalidInboxConsumer::becauseConsumerNameIsInvalid($consumerName);
		}

		if (preg_match(self::CONSUMER_NAME_PATTERN, $consumerName, $matches) !== 1) {
			throw InvalidInboxConsumer::becauseConsumerNameIsInvalid($consumerName);
		}

		if (!array_key_exists($matches[1], $this->contexts)) {
			throw InvalidInboxConsumer::becauseContextIsNotConfigured($consumerName);
		}
	}
}
