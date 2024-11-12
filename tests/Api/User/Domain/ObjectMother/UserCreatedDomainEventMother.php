<?php

declare(strict_types=1);

namespace Tests\Api\User\Domain\ObjectMother;

use CodelyTv\Mooc\Courses\Domain\Course;
use CodelyTv\Mooc\Courses\Domain\CourseCreatedDomainEvent;
use CodelyTv\Mooc\Courses\Domain\CourseDuration;
use CodelyTv\Mooc\Courses\Domain\CourseName;
use CodelyTv\Mooc\Shared\Domain\Courses\CourseId;
use CodelyTv\Tests\Mooc\Courses\Domain\CourseDurationMother;
use CodelyTv\Tests\Mooc\Courses\Domain\CourseIdMother;
use CodelyTv\Tests\Mooc\Courses\Domain\CourseNameMother;
use Manager\Api\User\Domain\User;
use Manager\Api\User\Domain\UserCreatedDomainEvent;
use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserId;
use Manager\Api\User\Domain\ValueObjects\UserName;
use Manager\Api\User\Domain\ValueObjects\UserPassword;
use Manager\Api\User\Domain\ValueObjects\UserUuid;
use Tests\Shared\Domain\UuidMother;

final class UserCreatedDomainEventMother
{
	public static function create(
		?UserId $id = null,
		?UserUuid $uuid = null,
		?UserName $name = null,
        ?UserEmail $email = null,
        ?UserPassword $password = null,
	): UserCreatedDomainEvent {
		return new UserCreatedDomainEvent(
			$id?->value() ?? UserIdMother::create(1)->value(),
            $uuid?->value() ?? UserUuidMother::create()->value(),
			$name?->value() ?? UserNameMother::create()->value(),
			$email?->value() ?? UserEmailMother::create()->value(),
            $password?->value() ?? UserPasswordMother::create()->value(),
		);
	}

	public static function fromUser(User $user): UserCreatedDomainEvent
	{
		return self::create($user->id(), $user->uuid(), $user->name(), $user->email(), $user->password());
	}
}
