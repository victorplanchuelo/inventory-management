<?php

declare(strict_types=1);

namespace Manager\Domain\User;

use Manager\Domain\User\ValueObjects\UserEmail;

interface UserRepository
{
	public function searchAll(): array;
	public function searchByEmail(UserEmail $email): ?User;
	public function create(User $user): ?User;
}
