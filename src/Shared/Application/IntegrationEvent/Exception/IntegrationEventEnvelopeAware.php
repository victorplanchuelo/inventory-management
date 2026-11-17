<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent\Exception;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

interface IntegrationEventEnvelopeAware
{
	public function envelope(): IntegrationEventEnvelope;
}
