<?php

namespace Tests\ObjectMothers\User;

use Manager\Domain\User\ValueObjects\UserEmail;
use Tests\Shared\Domain\EmailMother;

final class UserEmailMother
{
    public static function create(?string $value = null): UserEmail
    {
        return new UserEmail($value ?? EmailMother::create());
    }
}
