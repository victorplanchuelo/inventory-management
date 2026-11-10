<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Outbox;

use RuntimeException;

final class OutboxClaimLostException extends RuntimeException {}
