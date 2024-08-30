<?php

declare(strict_types=1);

namespace Tests\Shared\Domain;

final class PasswordMother
{
	public static function create(): string
	{
		return MotherCreator::random()->password(8, 50);
	}
}
