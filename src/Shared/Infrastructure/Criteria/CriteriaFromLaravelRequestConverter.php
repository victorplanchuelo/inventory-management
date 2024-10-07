<?php
declare(strict_types=1);

namespace Manager\Shared\Infrastructure\Criteria;

use Illuminate\Http\Request;
use Manager\Shared\Domain\Criteria\Criteria;
use Manager\Shared\Domain\Criteria\InvalidCriteriaException;

final class CriteriaFromLaravelRequestConverter
{
    private CriteriaFromUrlConverter $converter;

    public function __construct()
    {
        $this->converter = new CriteriaFromUrlConverter();
    }

    /**
     * @throws InvalidCriteriaException
     */
    public static function convert(Request $request): Criteria
    {
        return (new self())->toCriteria($request);
    }

    /**
     * @throws InvalidCriteriaException
     */
    public function toCriteria(Request $request): Criteria
    {
        $url = $request->fullUrl();

        return $this->converter->toCriteria($url);
    }

    public function toFiltersPrimitives(Request $request): array
    {
        $url = $request->fullUrl();

        return $this->converter->toFiltersPrimitives($url);
    }
}
