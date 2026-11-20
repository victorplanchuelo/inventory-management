<?php

declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Uuid;

use Manager\Shared\Domain\UuidGenerator;
use Ramsey\Uuid\Uuid;

final readonly class RamseyUuidGenerator implements UuidGenerator
{
	public function generate(): string
	{
		return Uuid::uuid4()->toString();
	}
}
