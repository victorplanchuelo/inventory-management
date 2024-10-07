<?php
declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Criteria;
use Manager\Shared\Domain\Criteria\Criteria;
use Manager\Shared\Domain\Criteria\InvalidCriteriaException;

final class CriteriaFromUrlConverter
{
    /**
     * @throws InvalidCriteriaException
     */
    public function toCriteria(string $url): Criteria
    {
        $parsedUrl = parse_url($url);
        parse_str($parsedUrl['query'] ?? '', $searchParams);

        return Criteria::fromPrimitives(
            $searchParams['filters'],
            $searchParams['orderBy'] ?? null,
            $searchParams['order'] ?? null,
            isset($searchParams['pageSize']) ? (int)$searchParams['pageSize'] : null,
            isset($searchParams['pageNumber']) ? (int)$searchParams['pageNumber'] : null
        );
    }

    public function toFiltersPrimitives(string $url): array
    {
        $parsedUrl = parse_url($url);
        parse_str($parsedUrl['query'] ?? '', $searchParams);

        return $searchParams['filters'];
    }
}
