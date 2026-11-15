<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\IntegrationEvent;

use Manager\Shared\Application\IntegrationEvent\IntegrationEventEnvelope;

final class IntegrationEventFixture
{
	public const EVENT_ID = '5a7dbb7a-1c3f-4f2e-9f6c-6a1d0b0c9e01';
	public const AGGREGATE_ID = 'stock-item-A-1';
	public const EVENT_NAME = 'inventory.stock_added';
	public const EVENT_VERSION = '1';
	public const OCCURRED_AT = '2026-11-10T12:34:56Z';

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	public static function data(array $overrides = []): array
	{
		return array_merge([
			'eventId' => self::EVENT_ID,
			'eventName' => self::EVENT_NAME,
			'eventVersion' => self::EVENT_VERSION,
			'aggregateType' => 'stock_item',
			'aggregateId' => self::AGGREGATE_ID,
			'aggregateVersion' => 3,
			'occurredAt' => self::OCCURRED_AT,
			'payload' => ['sku' => 'A-1', 'quantity' => 2],
			'correlationId' => 'correlation-1',
			'causationId' => 'causation-1',
		], $overrides);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function envelope(array $overrides = []): IntegrationEventEnvelope
	{
		return IntegrationEventEnvelope::fromArray(self::data($overrides));
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	public static function json(array $overrides = []): string
	{
		return json_encode(self::data($overrides), JSON_THROW_ON_ERROR);
	}
}
