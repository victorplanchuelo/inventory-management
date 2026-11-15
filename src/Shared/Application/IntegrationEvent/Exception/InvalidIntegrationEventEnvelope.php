<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent\Exception;

use RuntimeException;

final class InvalidIntegrationEventEnvelope extends RuntimeException
{
	public static function becauseFieldIsMissing(string $field): self
	{
		return new self(sprintf('The integration-event envelope is missing the <%s> field.', $field));
	}

	public static function becauseFieldIsInvalid(string $field): self
	{
		return new self(sprintf('The integration-event envelope field <%s> is invalid.', $field));
	}

	public static function becausePayloadIsInvalid(): self
	{
		return new self('The integration-event envelope payload must be a JSON object or array.');
	}

	public static function becauseJsonIsInvalid(): self
	{
		return new self('The integration-event envelope is not valid JSON.');
	}

	public static function becauseItIsNotAnObject(): self
	{
		return new self('The integration-event envelope must be a JSON object.');
	}
}
