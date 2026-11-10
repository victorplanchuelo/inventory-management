<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Manager\Shared\Infrastructure\Outbox\OutboxProducerValidator;
use Manager\Shared\Infrastructure\Outbox\UnknownOutboxProducerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class OutboxProducerValidatorTest extends UnitTestCase
{
	#[Test]
	#[DataProvider('registeredProducers')]
	public function it_should_accept_registered_producers(string $producer): void
	{
		$validator = new OutboxProducerValidator(['inventory', 'orders', 'catalog']);

		$validator->assertIsRegistered($producer);

		$this->addToAssertionCount(1);
	}

	public static function registeredProducers(): iterable
	{
		yield 'inventory' => ['inventory'];
		yield 'orders' => ['orders'];
		yield 'catalog' => ['catalog'];
	}

	#[Test]
	#[DataProvider('unknownProducers')]
	public function it_should_reject_unknown_producers(string $producer): void
	{
		$this->expectException(UnknownOutboxProducerException::class);

		$validator = new OutboxProducerValidator(['inventory', 'orders', 'catalog']);

		$validator->assertIsRegistered($producer);
	}

	public static function unknownProducers(): iterable
	{
		yield 'not registered' => ['shipping'];
		yield 'empty string' => [''];
		yield 'case mismatch' => ['Inventory'];
		yield 'with whitespace' => [' inventory'];
	}

	#[Test]
	public function it_should_reject_any_producer_when_no_producers_are_registered(): void
	{
		$this->expectException(UnknownOutboxProducerException::class);

		$validator = new OutboxProducerValidator([]);

		$validator->assertIsRegistered('inventory');
	}
}
