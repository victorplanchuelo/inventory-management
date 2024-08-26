<?php

declare(strict_types=1);

namespace Manager\Shared\Domain;

interface RandomNumberGenerator
{
	public function generate(): int;
}
