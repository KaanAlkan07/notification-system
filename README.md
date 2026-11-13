# Event-Driven Notification System

A Laravel 12 API for scheduling, sending, and tracking SMS, email, and push notifications. PostgreSQL persists both notifications and queue jobs. Redis enforces a shared limit of 100 send admissions per second per channel.

## Run locally

With Docker and Compose v2 installed, run from this directory.

```sh
docker compose up --build
```

The API listens at [http://127.0.0.1:8080](http://127.0.0.1:8080/health). Compose migrates PostgreSQL before starting the API and worker. The included provider is a local HTTP simulator. Use synthetic recipients and content. Set `HTTP_PORT` in `.env` to change the host port.

```sh
curl -i http://127.0.0.1:8080/api/v1/notifications \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-order-42-sms' \
  -H 'X-Correlation-ID: demo-order-42' \
  -d '{"recipient":"+15550102030","channel":"sms","content":"Your order is ready.","priority":"high"}'
```

The response is HTTP 202. Follow its `Location` header to see the current state. `accepted` means the provider acknowledged the message. The POST response can already reflect a worker transition when the queue is fast.

```sh
curl -i http://127.0.0.1:8080/api/v1/notification-batches \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-order-batch' \
  -d '{"notifications":[{"recipient":"user@example.test","channel":"email","content":"Your order summary is ready."},{"recipient":"demo-device","channel":"push","content":"Reminder","scheduled_at":"2030-01-01T10:00:00Z"}]}'

curl 'http://127.0.0.1:8080/api/v1/notifications?status=accepted&channel=sms&per_page=25'
curl http://127.0.0.1:8080/health
curl http://127.0.0.1:8080/metrics
```

Use `next_cursor` as the next request's `cursor`, retaining the same filters. The batch URL exposes status counts and paginated members. To cancel a pending notification or the next attempt of a retrying notification, send `POST /api/v1/notifications/{id}/cancel`. See the [OpenAPI 3.1 contract](docs/openapi.yaml) for request and response schemas.

Set `NOTIFICATION_API_TOKEN` and send `Authorization: Bearer <token>` to protect notification routes and metrics. In environments other than `local` and `testing`, these routes require a configured token. The token grants access to all notifications in this single-tenant deployment.

## Webhook.site provider

Create a dedicated URL by sending `POST https://webhook.site/token` with this JSON, or set the equivalent default response in the Webhook.site UI. The returned `uuid` identifies the endpoint. The static response is sufficient for a smoke test. [Webhook.site token API](https://docs.webhook.site/api/tokens.html#create-token)

```json
{
  "default_status": 202,
  "default_content_type": "application/json",
  "default_content": "{\"messageId\":\"6e31d892-1d74-46b1-8e10-dd1f67571ece\",\"status\":\"accepted\",\"timestamp\":\"2026-11-13T00:00:00Z\"}"
}
```

Add `NOTIFICATION_PROVIDER_URL=https://webhook.site/<uuid>` to your local `.env` and rerun `docker compose up -d`. Send a synthetic notification with a fresh idempotency key. The outbound JSON contains `to`, `channel`, and `content`. Every provider attempt carries the same notification ID in `Idempotency-Key` and forwards `X-Correlation-ID`. Inspect the capture in Webhook.site and the result in the API.

## Delivery behavior

| Provider or worker outcome | Application behavior |
| --- | --- |
| HTTP 202 with valid acknowledgment | Records `accepted` and the provider message ID. |
| HTTP 429 or 503 | Retries up to five provider attempts, assuming the simulator rejected the request before acceptance. Uses exponential backoff with jitter and honors `Retry-After` up to 300 seconds. |
| Permanent 4xx, retry budget exhausted, or retry window exceeds policy | Records `failed` with a failure code. |
| Timeout, lost connection, malformed acknowledgment, or lost worker after claim | Records `uncertain` for reconciliation. |

Webhook.site does not deduplicate repeated requests. Before using these retry rules with another provider, confirm that it honors the stable idempotency key or guarantees that its retryable responses precede acceptance. Cancellation and worker claims use the same row lock. A job that finds a cancelled row exits without contacting the provider. The queue may retain that job until its scheduled time.

An idempotency key is optional and scoped to the creation endpoint. The same normalized payload and key return the existing resource with HTTP 202. Reusing a key with different data returns HTTP 409. To preserve delayed delivery, schedules with fractional seconds round up to the next whole second. Past schedules run as soon as a worker is available.

## Architecture and operations

See [the architecture](docs/architecture.md) for the transaction boundary, state machine, and scaling tradeoffs.

- Run more workers with `docker compose up -d --scale worker=4`. Workers poll high, normal, then low. Run `docker compose --profile priority-lanes up -d` to start dedicated normal and low workers alongside the shared pool.
- Queue depth includes scheduled and reserved jobs. Delivery outcomes and acceptance latency use a five-minute window. Latency starts at the scheduled time. A window with zero observations reports `NaN` for percentages and mean latency.
- The local PHP HTTP server suits the assessment environment. A deployed API can use PHP-FPM behind a TLS reverse proxy with request size limits. This repository includes no measured production throughput guarantee. At 100 admissions per second per channel, the theoretical admission cap is 8.64 million per channel per day. Actual throughput depends on worker concurrency, provider latency, retry load, and PostgreSQL.
- Notifications contain recipient and message data. The API leaves both fields out of status responses. Use an appropriate retention policy and database access controls for a real deployment.

## Tests

Run the suite in an isolated, in-memory SQLite database inside the built image.

```sh
docker compose run --rm --no-deps -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array app composer test
```

With PHP 8.4 and Composer available locally, run `composer install` and then `composer test`. CI also uses a separate PostgreSQL test database and real Redis, runs Pint, and exercises HTTP → worker → provider. To run the Redis integration test, set `RUN_REDIS_INTEGRATION=1` and point `REDIS_HOST` and `REDIS_PORT` at a test Redis instance.
