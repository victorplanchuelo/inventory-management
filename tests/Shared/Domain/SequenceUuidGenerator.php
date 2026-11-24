<?php

declare(strict_types=1);

namespace Tests\Shared\Domain;

use Manager\Shared\Domain\UuidGenerator;
use RuntimeException;

final class SequenceUuidGenerator implements UuidGenerator
{
	private int $calls = 0;

	/**
	 * @param list<string> $uuids
	 */
	public function __construct(private readonly array $uuids) {}

	public function generate(): string
	{
		$uuid = $this->uuids[$this->calls] ?? null;

		if ($uuid === null) {
			throw new RuntimeException('The sequence UUID generator has no more identifiers.');
		}

		$this->calls++;

		return $uuid;
	}

	public function calls(): int
	{
		return $this->calls;
	}
}
