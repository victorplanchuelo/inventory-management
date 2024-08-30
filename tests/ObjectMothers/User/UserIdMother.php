<?php

namespace Tests\ObjectMothers\User;

use Manager\Domain\User\ValueObjects\UserId;
use Tests\Shared\Domain\IntegerMother;

final class UserIdMother
{
    public static function create(?int $value = null): UserId
    {
        return new UserId($value ?? IntegerMother::create());
    }
}
