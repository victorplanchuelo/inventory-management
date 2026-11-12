<?php

declare(strict_types=1);

return [
	'producers' => ['inventory', 'orders', 'catalog', ],

	'exchange' => env('OUTBOX_EXCHANGE', 'integration_events'),

	'lease_seconds' => (int) env('OUTBOX_LEASE_SECONDS', 300),

	'publish_batch' => (int) env('OUTBOX_PUBLISH_BATCH', 100),

	'confirm_timeout_seconds' => (float) env('OUTBOX_CONFIRM_TIMEOUT_SECONDS', 5.0),

	// The Outbox always uses the application's default database connection.
	// An independent connection would break atomicity with the business write.
	'published_retention_days' => (int) env('OUTBOX_PUBLISHED_RETENTION_DAYS', 7),

	'retry' => [
		'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),
		'base_backoff_seconds' => (int) env('OUTBOX_BASE_BACKOFF_SECONDS', 5),
		'backoff_factor' => (int) env('OUTBOX_BACKOFF_FACTOR', 2),
		'max_backoff_seconds' => (int) env('OUTBOX_MAX_BACKOFF_SECONDS', 600),
	],

	'logging' => [
		'enabled' => (bool) env('OUTBOX_LOGGING_ENABLED', true),
		'batch_summary' => (bool) env('OUTBOX_LOGGING_BATCH_SUMMARY', true),
	],
];
