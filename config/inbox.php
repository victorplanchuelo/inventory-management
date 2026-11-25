<?php

declare(strict_types=1);

return [
	'contexts' => [
		'orders' => 'orders_inbox',
	],

	// Fallback only. InboxExchangeValidator enforces equality with outbox.exchange.
	'exchange' => env('INBOX_EXCHANGE', env('OUTBOX_EXCHANGE', 'integration_events')),

	'max_retries' => (int) env('INBOX_MAX_RETRIES', 5),

	'confirm_timeout_seconds' => (float) env('INBOX_CONFIRM_TIMEOUT_SECONDS', 5.0),

	'retention_days' => env('INBOX_RETENTION_DAYS'),

	'logging' => [
		'enabled' => (bool) env('INBOX_LOGGING_ENABLED', true),
	],
];
