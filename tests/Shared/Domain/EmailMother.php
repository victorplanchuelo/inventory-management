<?php

declare(strict_types=1);

namespace Tests\Shared\Domain;

use Manager\Api\User\Domain\ValueObjects\UserEmail;

final class EmailMother
{
	public static function create(): string
	{
		return MotherCreator::random()->email;
	}

    public static function set(string $email): UserEmail
    {
        return new UserEmail($email);
    }
}
