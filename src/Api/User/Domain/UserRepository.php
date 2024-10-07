<?php

declare(strict_types=1);

namespace Manager\Api\User\Domain;

use Manager\Shared\Domain\Criteria\Criteria;

interface UserRepository
{
	public function searchAll(): array;
	public function matching(Criteria $criteria): array;
	public function save(User $user): User;
}
