# Event-Driven Notification System

A Laravel 12 API for scheduling, sending, and tracking SMS, email, and push notifications. PostgreSQL persists both notifications and queue jobs. Redis limits each channel to 100 in-flight or recently completed provider requests across all workers.

## Run locally

With Docker and Compose v2 installed, run from this directory.

```sh
docker compose up --build
```

Open [the notification console](http://127.0.0.1:8080) to compose, schedule, filter, and inspect notifications in the browser. Compose migrates PostgreSQL before starting the API and worker. The included provider is a local HTTP simulator. Use synthetic recipients and content. Set `HTTP_PORT` in `.env` to change the host port.

For the assessment's external provider demonstration, follow [Webhook.site provider](#webhooksite-provider) and use the same console or API.

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

Add `NOTIFICATION_PROVIDER_URL=https://webhook.site/<uuid>` to your local `.env` and rerun `docker compose up -d`. Send a synthetic notification with a fresh idempotency key. The outbound JSON contains `to`, `channel`, and `content`. The provider idempotency key stays constant across automatic attempts within a delivery cycle. Each manual retry starts a new cycle with a distinct key. Every request forwards `X-Correlation-ID`. Inspect the capture in Webhook.site and the result in the API.

## Send real email over SMTP

Copy `.env.example` to `.env` and set the following values for your email provider. Store the SMTP password, app password, or SMTP API key in the ignored `.env` file or pass it through a runtime secret store.

```dotenv
NOTIFICATION_EMAIL_PROVIDER=smtp
NOTIFICATION_SMTP_SCHEME=smtp
NOTIFICATION_SMTP_HOST=smtp.example.com
NOTIFICATION_SMTP_PORT=587
NOTIFICATION_SMTP_USERNAME=
NOTIFICATION_SMTP_PASSWORD=
NOTIFICATION_MAIL_FROM=
NOTIFICATION_MAIL_FROM_NAME="Bildirim Merkezi"
```

Use `smtp` with STARTTLS (commonly port 587) or `smtps` with implicit TLS (commonly port 465). Pick a sender address authorized by the provider and a recipient you control for the first test. TLS is required and the server certificate is verified. Recreate the worker with the updated environment. For Compose, run `docker compose up -d --build`.

In **Yeni bildirim**, choose **E-posta**, enter the recipient and message, and select **Kuyruğa ekle**. SMTP mode sends plain text with the subject **Bildirim Merkezi**. **Kabul edildi** means the SMTP server queued the message. Check the recipient inbox, spam folder, or provider activity log for delivery. Explicit temporary SMTP rejections use the queue retry policy. An interrupted conversation can leave acceptance uncertain and requires checking the provider before resending.

## Track real SMS delivery with Twilio

Set `NOTIFICATION_SMS_PROVIDER=twilio` in the runtime environment, then set `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and `TWILIO_FROM` to your account credentials and SMS-capable Twilio sender in E.164 format. Set `TWILIO_CALLBACK_BASE_URL` to the public HTTPS origin that routes `/webhooks/twilio/sms/*` to this application. Run migrations and restart the API and workers with those environment variables. Keep the token in the runtime secret store. For a deployed instance, protect notification routes with `APP_ENV=production` and `NOTIFICATION_API_TOKEN`, or expose only the callback route through the proxy.

The worker sends the callback URL with each SMS request. In the console, the delivery filter and notification detail distinguish **Teslimat onayı bekleniyor**, **Operatöre iletildi**, **Teslim edildi**, and **Teslim edilemedi**. The API exposes the same information in `delivery_status`, `delivery_updated_at`, and `delivery_error_code`. Delivery records reflect reports Twilio receives from carriers and, when available, handsets. SMS delivery receipts do not report whether the recipient read the message. [Twilio delivery status guide](https://www.twilio.com/docs/messaging/guides/track-outbound-message-status)

The `webhook` SMS mode uses the configured provider. Email uses its own provider setting, and push uses the configured webhook provider. If Twilio or the carrier sends no receipt, the console keeps the most recent known state. An uncertain Twilio request is reconciled when a matching signed callback arrives.

## Delivery behavior

| Provider or worker outcome | Application behavior |
| --- | --- |
| HTTP 202 with valid acknowledgment | Records `accepted` and the provider message ID. RFC 3339 fractional seconds may have more than six digits. |
| HTTP 429 or 503 | Retries up to five provider attempts, assuming the simulator rejected the request before acceptance. Uses exponential backoff with jitter and honors `Retry-After` up to 300 seconds. |
| Permanent 4xx, retry budget exhausted, or retry window exceeds policy | Records `failed` with a failure code. |
| Timeout, lost connection, malformed acknowledgment, or lost worker after claim | Records `uncertain`. If the webhook provider supports lookup, a queue job automatically checks its receipt. |

Webhook.site does not deduplicate repeated requests. Before using these retry rules with another provider, confirm that it honors the stable idempotency key or guarantees that its retryable responses precede acceptance. Cancellation and worker claims use the same row lock. A job that finds a cancelled row exits without contacting the provider. The queue may retain that job until its scheduled time.

To retry a failed notification, open its details and select **Yeniden dene**. The notification keeps its ID, channel and priority and gets a fresh budget of five attempts. The API equivalent is `POST /api/v1/notifications/{id}/retry` with the current `retry_generation` from the notification. Stale requests receive HTTP 409, and uncertain deliveries require reconciliation.

The Compose simulator stores receipts in the `provider-data` volume and enables lookup by default. For another webhook provider, set `NOTIFICATION_PROVIDER_STATUS_URL` to a status endpoint implementing the contract below. Set `NOTIFICATION_PROVIDER_STATUS_TOKEN` if that endpoint requires a bearer token. Recreate the worker after changing these settings.

The worker calls the status URL with HTTP GET and the same `Idempotency-Key`, `X-Correlation-ID` and `X-Delivery-Attempt` headers used for the submission. It handles these responses:

- HTTP 200 with `status: accepted`, a UUID `messageId` and a valid RFC 3339 `timestamp` records the existing acceptance without another POST.
- HTTP 200 with `status: rejected` and an integer `attempt` matching `X-Delivery-Attempt` may schedule a new POST within the existing retry budget. Return this result only if that attempt was irrevocably rejected. The provider must support resubmission with the same delivery key after rejection. An optional `Retry-After` header sets a minimum wait.
- A pending or unknown result, malformed response, or unavailable endpoint leaves the record uncertain. The job checks at most six times with increasing delays. Absence of a receipt never authorizes resending.

A standard Webhook.site URL answers submissions and lets you inspect captures, which do not implement the status contract above. Without an authoritative status endpoint, unresolved outcomes remain `uncertain`. The same applies to older uncertain records that were created without a configured lookup.

An idempotency key is optional and scoped to the creation endpoint. The same normalized payload and key return the existing resource with HTTP 202. Reusing a key with different data returns HTTP 409. To preserve delayed delivery, schedules with fractional seconds round up to the next whole second. Past schedules run as soon as a worker is available.

## Architecture and operations

See [the architecture](docs/architecture.md) for the transaction boundary, state machine, and scaling tradeoffs.

- Run more workers with `docker compose up -d --scale worker=4`. Workers poll high, normal, then low. Run `docker compose --profile priority-lanes up -d` to start dedicated normal and low workers alongside the shared pool.
- Queue depth includes scheduled and reserved jobs. Delivery outcomes and acceptance latency use a five-minute window. Latency starts at the scheduled time. A window with zero observations reports `NaN` for percentages and mean latency.
- The overview charts use `GET /api/v1/notification-trends?range=24h` or `range=7d`. The API groups existing notification records into UTC hour or day buckets by their latest completion time. Acceptance rate is accepted divided by all completed notifications in the bucket, including failed and uncertain outcomes. A bucket with zero outcomes has no acceptance rate, and the current bucket is partial.
- The local PHP HTTP server suits the assessment environment. A deployed API can use PHP-FPM behind a TLS reverse proxy with request size limits. Actual throughput depends on worker concurrency, provider latency, retry load, and PostgreSQL. Each request uses a slot until one second after its response. Ambiguous requests and interrupted workers hold a slot for up to 60 seconds.
- Notifications contain recipient and message data. The API leaves both fields out of status responses. Use an appropriate retention policy and database access controls for a real deployment.

## Tests

Run the suite in an isolated, in-memory SQLite database inside the built image.

```sh
docker compose run --rm --no-deps -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array app composer test
```

With PHP 8.4 and Composer available locally, run `composer install` and then `composer test`. The suite uses in-memory SQLite by default. If you override `DB_CONNECTION` or `DB_DATABASE`, point the suite at a disposable test database, since the tests run migrations from scratch. CI uses a separate PostgreSQL test database and real Redis, runs Pint, and exercises HTTP → worker → provider. To run the Redis integration test, set `RUN_REDIS_INTEGRATION=1` and point `REDIS_HOST` and `REDIS_PORT` at a test Redis instance.

### Assessment verification

- **External provider.** A synthetic SMS created in the browser produced one Webhook.site POST with the expected `to`, `channel`, and `content`. Matching the correlation header, request idempotency key, and acknowledgment message ID confirmed the same request in the capture and API. Create a fresh URL for each demonstration when the previous URL has expired.
- **Burst load.** Twelve 1,000-item batches were submitted across four concurrent clients while eight workers consumed them on PostgreSQL 17 and Redis. The local provider received 4,000 requests per channel and observed a peak of 100 arrivals in any rolling second on each channel. The API accepted all 12,000 requests in 2.96 seconds. The last provider acknowledgment arrived 41.81 seconds after the first request. All notifications had one provider attempt, and the final queue and failed job counts were both zero. This was a short, local benchmark with a low-latency provider.
- **Concurrent idempotency.** Four identical replays of one 1,000-item batch returned the existing batch ID. The final database count stayed at exactly 12 batches and 12,000 notifications.
- **Retries under burst load.** A separate local run used 12,000 distinct synthetic recipients and simulated `429`, `503`, `422`, and lost responses in each channel. The API accepted the batches in 5.96 seconds. In 87.47 seconds, 11,991 notifications were accepted, 6 failed as expected, and 3 became uncertain after losing the provider response. Transient failures recovered on the next attempt after the requested 7-second wait. Persistent `503` stopped after five attempts. The provider observed 4,006 calls per channel, a peak of 100 in a rolling second per channel, and zero duplicate acceptances. No jobs remained in the queue.

### One-million-notification Docker Compose benchmark

On 14 November 2026, four clients submitted 1,000 batches of up to 1,000 notifications while ten workers processed them on PostgreSQL 17 and Redis. The driver limited the backlog to approximately 18,000 notifications. It submitted the final batch after 60 min 33 s and verified the final result after 61 min 55 s.

| Channel | Notifications | Provider calls | Accepted | Failed | Uncertain | Peak calls in any rolling second |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| SMS | 333,334 | 333,460 | 333,271 | 42 | 21 | 100 |
| Email | 333,333 | 333,459 | 333,270 | 42 | 21 | 100 |
| Push | 333,333 | 333,459 | 333,270 | 42 | 21 | 100 |
| Total | 1,000,000 | 1,000,378 | 999,811 | 126 | 63 | Each channel stayed within 100 |

The simulator recorded zero violations of the rolling rate limit and zero duplicate acceptances. Replaying three batches returned their original IDs. Of the 315 injected faults, 126 one-time `429` or `503` responses recovered after honoring `Retry-After: 7`, 63 persistent `503` responses stopped after five attempts, and 63 permanent `422` responses stopped after one. In the remaining 63 cases the provider accepted the notification but dropped the response. With provider lookup unavailable in this scenario, the application marked these `uncertain` without resending. No queue or failed jobs remained. See the [driver summary](tools/load-test/evidence/summary.json) and [PostgreSQL totals](tools/load-test/evidence/database.json).

This was a local benchmark against a low-latency synthetic provider. Its acceptance and throughput figures measure the application and the simulator, with no real recipients involved.

To reproduce it, start Docker with Linux containers and make sure the `docker` command is on your PATH. Use PowerShell 7 and Docker Compose 2.24.4 or newer. Run these from the repository root:

```powershell
# Short run to check that the benchmark works on your machine
pwsh -NoProfile -File ./tools/load-test/run.ps1 -Total 3000

# Full run with the same worker count and backlog target
pwsh -NoProfile -File ./tools/load-test/run.ps1 -Total 1000000 -Workers 10 -Watermark 18000
```

Each run creates a separate Compose project with an internal network, a synthetic provider, and temporary database volumes. It publishes no host ports and removes its containers and volumes when it exits. Find the run reports under `tools/load-test/results/notification-load-*/`. A passing run writes `Verified` to `outcome.txt` after checking the driver results against PostgreSQL. `summary.json` records the rolling peaks and retry timings, `fault-api-evidence.json` records each simulated fault, and `database.json` records the final state and queue counts.
