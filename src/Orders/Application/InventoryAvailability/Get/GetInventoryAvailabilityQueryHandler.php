<?php

declare(strict_types=1);

namespace Manager\Orders\Application\InventoryAvailability\Get;

use Manager\Orders\Domain\InventoryAvailability\InventoryAvailabilityRepository;
use Manager\Shared\Domain\Bus\Query\QueryHandler;

final readonly class GetInventoryAvailabilityQueryHandler implements QueryHandler
{
	public function __construct(private InventoryAvailabilityRepository $repository) {}

	public function __invoke(GetInventoryAvailabilityQuery $query): ?InventoryAvailabilityResponse
	{
		$availability = $this->repository->findByProductId($query->productId());

		return $availability === null
			? null
			: InventoryAvailabilityResponse::fromInventoryAvailability($availability);
	}
}
