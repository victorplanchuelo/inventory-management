<?php

declare(strict_types=1);

namespace Manager\Shared\Domain;

interface UuidGenerator
{
	public function generate(): string;
}
