<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Uuid;

use Manager\Shared\Infrastructure\Uuid\RamseyUuidGenerator;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

final class RamseyUuidGeneratorTest extends UnitTestCase
{
	#[Test]
	public function it_should_generate_a_valid_uuid_on_every_call(): void
	{
		$generator = new RamseyUuidGenerator();

		$first = $generator->generate();
		$second = $generator->generate();

		self::assertTrue(Uuid::isValid($first));
		self::assertTrue(Uuid::isValid($second));
		self::assertNotSame($first, $second);
	}
}
