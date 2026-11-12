<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\PhpUnit;

use Psr\Log\AbstractLogger;
use Stringable;

final class CollectingLogger extends AbstractLogger
{
	/** @var list<array{level: string, message: string, context: array<string, mixed>}> */
	private array $records = [];

	/** @param array<string, mixed> $context */
	public function log($level, string | Stringable $message, array $context = []): void
	{
		$this->records[] = [
			'level' => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		];
	}

	/** @return list<array{level: string, message: string, context: array<string, mixed>}> */
	public function records(): array
	{
		return $this->records;
	}

	public function json(): string
	{
		return (string) json_encode($this->records, JSON_THROW_ON_ERROR);
	}
}
