<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain;

use Manager\Api\User\Domain\ValueObjects\UserEmail;
use Manager\Api\User\Domain\ValueObjects\UserUuid;

interface UserRepository
{
	public function searchAll(): array;
    public function search(UserUuid $uuid): ?User;
	public function searchByEmail(UserEmail $email): ?User;
	public function save(User $user): User;
}
