# Outbox Operations

## 1. Purpose

This runbook describes how to operate the shared Transactional Outbox and its RabbitMQ publisher.

It covers execution, supervision, monitoring, retry handling, terminal failures, retention and purge. It does not describe bounded-context business behaviour or consumer-side processing.

## 2. Responsibilities and Limitations

The Outbox guarantees that an integration event is persisted in the same database transaction as the business change that produced it. Publication happens asynchronously after that transaction commits.

The Outbox is a delivery mechanism, not an Event Store:

- It stores the event envelope required for publication, not the complete history of an aggregate.
- It does not provide event replay, temporal queries or aggregate reconstruction.
- Published records may be purged according to the retention policy.
- Purged published records are irrecoverable.

Publication is at least once. A crash after RabbitMQ confirms a message but before the database records the publication can produce a duplicate delivery with the same event identity. Consumers must be idempotent.

The Outbox does not guarantee global ordering. `aggregateVersion` is optional and OD-002 remains open. Do not rely on Outbox order for correctness unless a future decision explicitly provides that guarantee.

## 3. Components

| Component | Responsibility |
|---|---|
| `outbox` table | Persists one row per integration event, owned by the `producer` that recorded it. |
| `MySqlOutboxRecorder` | Appends records inside the caller's business transaction. |
| `MySqlOutboxRepository` | Claims due records, fences claim ownership, applies state transitions, reports status and purges eligible published records. |
| `OutboxErrorSanitizer` | Redacts credential-like values from error messages before persistence, logging and status display. |
| `OutboxPublisher` | Drains a bounded batch, serializes each record and publishes it through RabbitMQ. |
| `RabbitMqConfirmedPublisher` | Publishes with persistent delivery, mandatory routing and positive publisher confirmation. |
| `manager:integration-events:outbox:publish` | Short-lived publisher drain command. |
| `manager:integration-events:outbox:status` | Read-only operational status report. |
| `manager:integration-events:outbox:requeue` | Explicit operator recovery for a terminally failed record. |
| `manager:integration-events:outbox:purge` | Deletes eligible published records beyond the retention window. |

The Outbox always uses the application's default database connection. It must share that connection with the business write so both changes commit or roll back together. An independent Outbox connection is intentionally not configurable.

## 4. Publisher Execution Model

The publisher is a short-lived drain command:

```bash
php artisan manager:integration-events:outbox:publish --limit=100
```

It processes at most `--limit` records and exits. When the option is omitted, `outbox.publish_batch` is used.

The command claims and processes one record at a time:

1. Claim a due or expired record with `SELECT ... FOR UPDATE SKIP LOCKED`.
2. Assign a new claim token and publication attempt number.
3. Serialize the stored payload into the integration-event envelope.
4. Publish to RabbitMQ with persistent delivery, mandatory routing and publisher confirmation.
5. Mark the record `published` only after positive confirmation and successful routing.
6. Schedule a retry, or mark the record `failed`, when publication does not succeed.

Multiple publisher processes may run concurrently. Row locking and claim tokens prevent them from normally claiming the same record. A stale publisher cannot change a record after its claim has expired or been reassigned.

## 5. Configuration

| Environment variable | Config key | Default | Purpose |
|---|---|---|---|
| `OUTBOX_EXCHANGE` | `outbox.exchange` | `integration_events` | RabbitMQ exchange used for integration events. |
| `OUTBOX_LEASE_SECONDS` | `outbox.lease_seconds` | `300` | Claim lease duration. Must exceed the confirmation timeout and normal publication time. |
| `OUTBOX_PUBLISH_BATCH` | `outbox.publish_batch` | `100` | Default number of records processed by one drain execution. |
| `OUTBOX_CONFIRM_TIMEOUT_SECONDS` | `outbox.confirm_timeout_seconds` | `5.0` | Maximum wait for RabbitMQ publisher confirmation. |
| `OUTBOX_MAX_ATTEMPTS` | `outbox.retry.max_attempts` | `10` | Total publication attempts before terminal failure. |
| `OUTBOX_BASE_BACKOFF_SECONDS` | `outbox.retry.base_backoff_seconds` | `5` | Delay before the first retry. |
| `OUTBOX_BACKOFF_FACTOR` | `outbox.retry.backoff_factor` | `2` | Exponential backoff multiplier. |
| `OUTBOX_MAX_BACKOFF_SECONDS` | `outbox.retry.max_backoff_seconds` | `600` | Maximum retry delay. |
| `OUTBOX_PUBLISHED_RETENTION_DAYS` | `outbox.published_retention_days` | `7` | Default retention for published records. |
| `OUTBOX_LOGGING_ENABLED` | `outbox.logging.enabled` | `true` | Enables per-record publication logs. |
| `OUTBOX_LOGGING_BATCH_SUMMARY` | `outbox.logging.batch_summary` | `true` | Enables the batch summary log. |

RabbitMQ connection settings remain in `config/queue.php` under `queue.connections.rabbitmq`.

## 6. Supervision

Run the drain command periodically with Supervisor or cron. The example below follows the repository's existing Supervisor conventions for RabbitMQ consumers. Adapt paths, user and process count to the deployment.

```ini
[program:manager_outbox_publisher]
command      = /bin/sh -c 'while true; do php /var/www/html/artisan manager:integration-events:outbox:publish --limit=100; sleep 2; done'
process_name = %(program_name)s_%(process_num)02d
numprocs     = 1
startsecs    = 1
startretries = 10
stopwaitsecs = 300
stopasgroup  = true
killasgroup  = true
autostart    = true
autorestart  = true
```

Cron alternative:

```cron
* * * * * cd /var/www/html && php artisan manager:integration-events:outbox:publish --limit=100 >> /dev/null 2>&1
```

Operational guidance:

- The batch limit bounds the work per execution. Increase it only after checking broker latency, confirmation timeout and database contention.
- The lease must be longer than the confirmation timeout plus serialization and database update time. A lease that is too short increases duplicate deliveries.
- The publisher is safe to restart. Expired claims are recovered by later executions, and event identity is preserved across attempts.
- If the process is stopped, committed records remain pending and are published after it restarts.
- Do not run the publisher inside the business request or inside the business database transaction.

## 7. Monitoring

### Status command

```bash
php artisan manager:integration-events:outbox:status
```

The command reports:

- Counts for `pending`, `claimed`, `published` and `failed`.
- The subset of pending records waiting for a future retry.
- The age and creation time of the oldest pending record.
- Stale claims, active publishers and the last successful publication time.
- The current publisher configuration.
- Recent publication errors with event, producer, aggregate, attempt and sanitized error details.

It never prints stored payload contents. Error text is passed through the error-sanitization policy before display, so credential-like values are redacted. It reads database state only; it does not probe RabbitMQ broker health. Use the RabbitMQ management UI, `rabbitmq-diagnostics` or the platform health checks for broker connectivity.

Suggested alerts:

- The status command fails: investigate database connectivity or permissions.
- The oldest pending age keeps growing: the publisher is stopped, the broker is unavailable, the batch is too small or claims are stuck.
- `Failed` increases: inspect recent errors, fix the cause and decide whether to requeue.
- `Retrying pending` keeps growing: check exchange existence, routing keys, queue bindings and broker rejections.
- `Stale claims` is greater than zero: a publisher may have crashed. Confirm recovery after the lease expires.
- `Last published at` is old while pending records exist: the publisher is not making progress.
- The number of published records grows without bound: verify the purge schedule.

### Structured logs

The publisher emits these records:

| Message | Level | When |
|---|---|---|
| `outbox.record.published` | `info` | The record was confirmed by RabbitMQ and marked published. |
| `outbox.record.retry_scheduled` | `warning` | Publication failed and a retry was scheduled. |
| `outbox.record.failed` | `error` | The record reached terminal failure or could not be serialized. |
| `outbox.batch.summary` | `info` / `error` | One drain execution completed or aborted. |

Per-record context includes event identity, producer, aggregate type and identifier, attempt number, publisher identifier, correlation and causation identifiers, outcome, timestamps, and sanitized error class and message when applicable.

Payload contents are never logged. Error messages are treated as untrusted input and passed through `OutboxErrorSanitizer` before they are persisted, logged or shown by the status command. The policy redacts URI credentials, passwords, tokens, authorization headers, private keys and common provider token formats.

Sanitization is defense in depth, not a licence to put secrets in exception messages or integration-event payloads. Producers remain responsible for the contents of the payloads they define.

### Error sanitization

`last_error` is sanitized at three boundaries: when the publisher describes an exception, when the repository persists or reads it, and when the status command renders it. Legacy rows written before this policy are redacted when read, and exhaustion recovery rewrites them in sanitized form.

After redaction, control characters are normalized. CR/LF, tabs, non-printable C0/C1 controls and Unicode line or paragraph separators become single spaces, and the message is trimmed. This keeps each error on one logical line, prevents log forging through injected line breaks, and makes repeated sanitization idempotent.

The sanitizer is pattern-based. It covers common credential shapes but cannot infer arbitrary business secrets. Treat sanitized error text as safe for operations, not as a guarantee that every possible secret format has been detected.

## 8. Retry and Terminal Failure Semantics

The default retry sequence is 5, 10, 20, 40, 80, 160, 320, 600 and 600 seconds.

- Attempts 1 through 9 may be retried.
- The failure of attempt 10 marks the record `failed`.
- A pending record that already reached the attempt limit is moved to `failed` during claim recovery.
- An expired claim that already reached the attempt limit is moved to `failed` without starting an eleventh publication.
- `failed` is terminal. Automatic publication stops.
- Requeue is an explicit operator action.

A failed record preserves its event identity, payload bytes, attempt count and last error. The stored error text is sanitized. Requeue resets attempts and returns the record to `pending`; the same event identity and payload are published again. Requeue does not clear the previous sanitized `last_error`.

### Manual requeue procedure

1. Run the status command and identify the failed event.
2. Confirm that the root cause has been corrected.
3. Confirm that consumers are idempotent and prepared for a duplicate delivery.
4. Requeue the event:

```bash
php artisan manager:integration-events:outbox:requeue <event-id>
```

5. Run the publisher or wait for the next supervised execution.
6. Verify that the record becomes `published` and inspect the batch logs.

Requeue is refused for records that are not `failed`.

## 9. Retention and Purge

Published records are retained for seven days by default and can be purged with:

```bash
php artisan manager:integration-events:outbox:purge --published-older-than-days=7
```

Resolution order:

1. The command-line option.
2. `outbox.published_retention_days`.
3. The built-in default of seven days.

The purge deletes only rows with `status = published`, a non-null `published_at`, and `published_at` older than the cutoff. It never deletes `pending`, `claimed` or `failed` records.

The command is destructive and irreversible. Run it during a low-traffic window, verify the retention value before execution, and keep database backups according to the platform policy.

### Checks before reducing retention or purging

- Confirm that downstream consumers have processed the events they need. The Outbox does not provide a global consumption confirmation.
- Confirm consumer idempotency and Inbox retention. Consumers own their own deduplication records.
- Confirm that no reconciliation, audit, legal or incident-response process requires the published records.
- Confirm that a recent backup exists and that restore has been tested.
- Confirm that reducing retention does not break replay or recovery procedures.
- Confirm that the purge window is understood by the operators on duty.

The Outbox is not an Event Store. Once a published record is purged, its envelope cannot be reconstructed from the Outbox.

Terminal `failed` records are intentionally excluded from automatic purge. Deleting them requires a separate explicit operator action with review, backup and documented justification. The provided commands do not delete failed records automatically.

## 10. Consumer Independence and Inbox Retention

The Outbox publisher does not know whether consumers processed a message. Task 003 does not implement an Inbox and does not provide a global consumption confirmation. Task 004 defines consumer-side idempotency and Inbox responsibilities.

Do not purge published records merely because they were published. Publication confirms broker acceptance, not consumer processing. Consumer teams own their Inbox retention and reconciliation requirements.

## 11. Incident Checklist

1. Run the status command and record the counts, oldest pending age, stale claims and recent errors.
2. Check that the publisher process is running and restarting correctly.
3. Check RabbitMQ connectivity, exchange existence and queue bindings.
4. Check application logs for `outbox.batch.summary` and `outbox.record.*` entries.
5. If the backlog is growing, verify the batch limit, lease and confirmation timeout.
6. If failures are terminal, fix the root cause before requeueing.
7. If claims are stale, wait for the lease to expire and confirm recovery; investigate the crashed process.
8. If published records accumulate, verify the purge schedule and retention configuration.
9. Record the incident, the affected event identifiers and the recovery actions. Never paste payload contents, raw connection strings or unsanitized exception messages into incident tickets or chat.
