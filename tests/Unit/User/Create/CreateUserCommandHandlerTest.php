<?php

declare(strict_types=1);

namespace Tests\Unit\User\Create;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Manager\Application\User\Creator\CreateUserCommandHandler;
use Manager\Application\User\Creator\UserCreator;
use Manager\Domain\User\Exceptions\UserAlreadyExistsException;
use PHPUnit\Framework\Attributes\Test;
use Tests\ObjectMothers\User\UserMother;
use Tests\Unit\User\UsersModuleUnitTestCase;

final class CreateUserCommandHandlerTest extends UsersModuleUnitTestCase
{
	use RefreshDatabase;

	private CreateUserCommandHandler | null $handler;

	protected function setUp(): void
	{
		parent::setUp();

		$this->handler = new CreateUserCommandHandler(new UserCreator($this->repository()));
	}

	#[Test] public function it_should_create_a_valid_user(): void
	{
		$command = CreateUserCommandMother::create();

		$user = UserMother::fromRequest($command);
		//$domainEvent = UserCreatedDomainEventMother::fromCourse($course);

		$this->shouldSearchByEmailAndReturnNull($user->email());
		$this->shouldSave($user);
		//$this->shouldPublishDomainEvent($domainEvent);

		$this->dispatch($command, $this->handler);
	}

    #[Test] public function it_should_throw_an_exception_when_user_email_is_registered(): void
    {
        $this->expectException(UserAlreadyExistsException::class);

        $command = CreateUserCommandMother::create();
        $user = UserMother::fromRequest($command);

        $this->shouldSearchByEmailAndReturnUser($user->email(), $user);
        $this->dispatch($command, $this->handler);
    }
}
