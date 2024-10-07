<?php
declare(strict_types=1);

namespace Manager\Shared\Domain\Criteria;

use Exception;
final class InvalidCriteriaException extends Exception
{
    public function __construct()
    {
        parent::__construct('Page size is required when page number is defined');
    }
}
