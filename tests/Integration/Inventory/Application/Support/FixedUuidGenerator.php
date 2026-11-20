<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory\Application\Support;

use Manager\Shared\Domain\UuidGenerator;

final readonly class FixedUuidGenerator implements UuidGenerator
{
	public function __construct(private string $uuid) {}

	public function generate(): string
	{
		return $this->uuid;
	}
}
