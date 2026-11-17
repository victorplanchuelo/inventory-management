<?php

declare(strict_types=1);

namespace Manager\Shared\Application\IntegrationEvent\Exception;

use RuntimeException;

abstract class RetryableIntegrationEventFailure extends RuntimeException {}
