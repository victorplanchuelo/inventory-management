<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\IntegrationEvent;

use Manager\Shared\Application\IntegrationEvent\Exception\PermanentIntegrationEventFailure;

final class PermanentSubscriberFailure extends PermanentIntegrationEventFailure {}
