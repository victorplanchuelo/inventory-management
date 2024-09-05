<?php

declare(strict_types=1);

namespace Tests\Unit\User;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Hash;
use Manager\Domain\User\User;
use Manager\Domain\User\UserRepository;
use Manager\Domain\User\ValueObjects\UserEmail;
use Manager\Domain\User\ValueObjects\UserId;
use Manager\Domain\User\ValueObjects\UserPassword;
use Mockery\CompositeExpectation;
use Mockery\MockInterface;
use Tests\Shared\Infrastructure\PhpUnit\UnitTestCase;

abstract class UsersModuleUnitTestCase extends UnitTestCase
{
	private MockInterface | UserRepository | null $repository = null;

	public function createApplication()
	{
		$app = require Application::inferBasePath() . '/bootstrap/app.php';
		$app->make(Kernel::class)->bootstrap();

		return $app;
	}

	protected function shouldSave(User $user): void
	{
		$this->repository()
			->shouldReceive('create')
			->with($this->similarTo($user))
			->once()
			->andReturn(
				new User(new UserId(1), $user->name(), $user->email(), new UserPassword(Hash::make($user->password()->value())))
			);
	}

	protected function shouldSearchByEmailAndReturnNull(UserEmail $email): void
	{
		$this->shouldSearchByEmailExpectation($email)
			->andReturn(null);
	}

	protected function shouldSearchByEmailAndReturnUser(UserEmail $email, ?User $user): void
	{
		$this->shouldSearchByEmailExpectation($email)
			->andReturn($user);
	}

	protected function shouldSearchByEmailExpectation(UserEmail $email): CompositeExpectation
	{
		return $this->repository()
			->shouldReceive('searchByEmail')
			->with($this->similarTo($email))
			->once();
	}

	protected function shouldSearchAll(?array $users): void
	{
		$this->repository()
			->shouldReceive('searchAll')
			->once()
			->andReturn($users);
	}

	protected function repository(): MockInterface | UserRepository
	{
		return $this->repository ??= $this->mockClass(UserRepository::class);
	}
}
