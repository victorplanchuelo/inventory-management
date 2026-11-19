<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Logging;

interface ErrorSanitizer
{
	public function sanitize(string $message): string;
}
