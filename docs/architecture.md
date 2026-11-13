# Architecture

```mermaid
flowchart LR
    Client -->|HTTPS| API[Laravel API]
    API -->|One transaction| DB[(PostgreSQL)]
    DB --- Records[Notifications and idempotency]
    DB --- Jobs[High, normal and low queues]
    Jobs --> Workers[Laravel workers]
    Workers -->|Shared sliding window| Redis[(Redis)]
    Workers -->|POST plus stable request ID| Provider[Webhook provider]
    Workers -->|Delivery state| DB
    Monitoring -->|GET /health and /metrics| API
```

## Atomic acceptance

The API writes the notification and its database job on the same Laravel connection in one transaction. The database queue is the durable handoff to workers. This removes the two-write failure window of a separate broker. Laravel uses row locking with `SKIP LOCKED` for PostgreSQL job reservation. Any future move to a separate broker would require a transactional outbox and a relay.

Idempotency records use a unique hash of endpoint and key. Concurrent identical requests converge on the same resource. Conflicting payloads return 409. The optional key belongs to the caller's logical intent. Repeating a request with a new key creates a new notification.

## State transitions

- `pending` and `retrying` can transition to `processing` or `cancelled`.
- `processing` can transition to `accepted`, `retrying`, `failed`, or `uncertain`.
- `accepted` records the provider's 202 acknowledgment. End-device receipt would require authenticated provider callbacks.
- `uncertain` records a send whose outcome could not be established. Reconciliation requires provider evidence or an operator decision.

Workers claim with a short row-locking transaction and release the lock before network IO. A worker timeout is shorter than the queue reservation visibility timeout. A recovered stale claim becomes uncertain. A stable external idempotency header aids reconciliation and supports a provider that honors it.

## Scale and visibility

Independent worker processes and indexed PostgreSQL queries handle burst backlogs. Redis applies one sliding-window budget per channel across workers. Dedicated normal and low workers from the optional Compose profile reserve worker capacity when high-priority traffic saturates the shared pool. Each channel shares its rate budget across all priorities.

The API reads current state directly. Metrics expose queue depth by priority and aggregate recent delivery outcomes and latency. Logs carry correlation and notification IDs. For a larger installation, benchmark the actual provider latency, partition or archive terminal history, and export telemetry without scanning the transactional store. Default deployment targets a local assessment environment.
