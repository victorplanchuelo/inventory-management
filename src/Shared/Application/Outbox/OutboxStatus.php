<?php

declare(strict_types=1);

namespace Manager\Shared\Application\Outbox;

use InvalidArgumentException;

enum OutboxStatus: string
{
	case PENDING = 'pending';
	case CLAIMED = 'claimed';
	case PUBLISHED = 'published';
	case FAILED = 'failed';

	public function canTransitionTo(self $target): bool
	{
		return in_array($target, $this->allowedTransitions(), true);
	}

	public function transitionTo(self $target): self
	{
		if (!$this->canTransitionTo($target)) {
			throw new InvalidArgumentException(
				sprintf('Outbox status cannot transition from <%s> to <%s>.', $this->value, $target->value)
			);
		}

		return $target;
	}

	/** @return list<self> */
	private function allowedTransitions(): array
	{
		return match ($this) {
			self::PENDING => [self::CLAIMED, self::FAILED],
			self::CLAIMED => [self::CLAIMED, self::PENDING, self::PUBLISHED, self::FAILED],
			self::PUBLISHED => [],
			self::FAILED => [self::PENDING],
		};
	}
}
