<?php

declare(strict_types=1);

return [
	'producers' => ['inventory', 'orders', 'catalog', ],

	'exchange' => env('OUTBOX_EXCHANGE', 'integration_events'),

	'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 300),

	'publish_batch' => (int) env('OUTBOX_PUBLISH_BATCH', 100),
];
