<?php

declare(strict_types=1);

namespace Manager\Orders\Infrastructure\Api\Controllers;

use Illuminate\Http\JsonResponse;
use LogicException;
use Manager\Orders\Application\InventoryAvailability\Get\GetInventoryAvailabilityQuery;
use Manager\Orders\Application\InventoryAvailability\Get\InventoryAvailabilityResponse;
use Manager\Shared\ApiBaseController;
use Manager\Shared\Domain\Bus\Query\QueryBus;

final class GetInventoryAvailabilityController extends ApiBaseController
{
	private const PROBLEM_TYPE = 'https://stockflow.example/problems/inventory-availability-not-found';
	private const NOT_FOUND_DETAIL = 'No Inventory availability projection is available for this product yet. This does not mean that the product has no stock.';

	public function __construct(private readonly QueryBus $bus) {}

	public function __invoke(string $productId): JsonResponse
	{
		$response = $this->bus->ask(new GetInventoryAvailabilityQuery($productId));

		if ($response === null) {
			return new JsonResponse(
				[
					'type' => self::PROBLEM_TYPE,
					'title' => 'Inventory availability projection not found',
					'status' => 404,
					'detail' => self::NOT_FOUND_DETAIL,
					'advisory' => true,
					'consistency' => 'eventually_consistent',
				],
				404,
				['Content-Type' => 'application/problem+json']
			);
		}

		if (!$response instanceof InventoryAvailabilityResponse) {
			throw new LogicException(sprintf(
				'Unexpected response type <%s> for the Inventory availability query.',
				$response::class
			));
		}

		return new JsonResponse([
			'productId' => $response->productId(),
			'stockItemId' => $response->stockItemId(),
			'onHand' => $response->onHand(),
			'reserved' => $response->reserved(),
			'available' => $response->available(),
			'sourceOccurredAt' => $response->sourceOccurredAt(),
			'projectionUpdatedAt' => $response->projectionUpdatedAt(),
			'advisory' => true,
			'consistency' => 'eventually_consistent',
		]);
	}
}
