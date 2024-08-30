<?php

namespace Tests\ObjectMothers\User;



use Illuminate\Support\Facades\Hash;
use Manager\Domain\User\ValueObjects\UserPassword;
use Tests\Shared\Domain\PasswordMother;

final class UserPasswordMother
{
    public static function create(?string $value = null): UserPassword
    {
        return new UserPassword($value ?? PasswordMother::create());
    }
}
