<?php

declare(strict_types=1);

namespace Manager\Domain\User\ValueObjects;

use Illuminate\Support\Facades\Hash;
use Manager\Shared\Domain\ValueObject\StringValueObject;

final class UserPassword extends StringValueObject {
    public function __construct(string $value)
    {
        parent::__construct($value);
    }
}
