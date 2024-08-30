<?php

namespace Tests\ObjectMothers\User;

use Manager\Domain\User\ValueObjects\UserName;
use Tests\Shared\Domain\WordMother;

final class UserNameMother
{
    public static function create(?string $value = null): UserName
    {
        return new UserName($value ?? WordMother::create());
    }
}
