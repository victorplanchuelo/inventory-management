<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain;

use InvalidArgumentException;
use Manager\Api\User\Domain\UserCreatedDomainEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Domain\UuidMother;

final class UserCreatedDomainEventTest extends TestCase
{
	#[Test]
	public function it_should_reconstruct_an_event_when_aggregate_id_matches_uuid(): void
	{
		$uuid = UuidMother::create();

		$event = UserCreatedDomainEvent::fromPrimitives(
			$uuid,
			[
				'uuid' => $uuid,
				'name' => 'Test user',
				'email' => 'test@example.com',
				'password' => 'password',
			],
			UuidMother::create(),
			'2026-11-07T18:44:27+00:00'
		);

		self::assertSame($uuid, $event->aggregateId());
	}

	#[Test]
	public function it_should_reject_an_event_when_aggregate_id_does_not_match_uuid(): void
	{
		$this->expectException(InvalidArgumentException::class);

		UserCreatedDomainEvent::fromPrimitives(
			UuidMother::create(),
			[
				'uuid' => UuidMother::create(),
				'name' => 'Test user',
				'email' => 'test@example.com',
				'password' => 'password',
			],
			UuidMother::create(),
			'2026-11-07T18:44:27+00:00'
		);
	}
}
