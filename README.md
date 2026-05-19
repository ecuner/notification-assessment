# Event-Driven Notification System

## Overview

This project is an API-first Laravel 13 notification system designed for asynchronous and high-throughput delivery.

Core capabilities:

- Single and batch notification creation
- Queue-based async delivery with channel-aware processing
- Rate limiting (`100 messages/second/channel`)
- Idempotent create operations using `Idempotency-Key` header
- Cancellation before final states
- Retry APIs for failed notifications and failed batches
- Metrics and health endpoints

All API routes are versioned under `/api/v1`.

## Tech Stack

Primary packages used:

- `spatie/laravel-query-builder`
- `dedoc/scramble`
- `pestphp/pest`

Redis is used for queueing/rate-limit flow because the system targets high throughput and concurrent job processing.

## Authentication

`X-API-Key` is required on all API endpoints except health:

- Public: `GET /api/v1/health`
- Protected: all other `/api/v1/*` routes

## Installation

### Docker Installation

1. Copy env:

```bash
cp .env.example .env
```

2. Set required values in `.env`:

- `NOTIFICATIONS_API_KEY` (used as `X-API-Key` on protected endpoints)
- `NOTIFICATIONS_PROVIDER_URL` (upstream/mock provider URL, i.e. https://webhook.site/{your-uuid} )

3. Build and start containers:

```bash
docker compose up -d --build
```

4. Run migrations and seeders (one-time):

```bash
docker compose exec app php artisan migrate --seed --force
```

5. API is available from the app container’s exposed host port.

Docker composition includes separate queue workers for priorities:

- `worker-high` -> `notifications-high`
- `worker-normal` -> `notifications-normal`
- `worker-low` -> `notifications-low`

### Local Installation

1. Install dependencies:

```bash
composer install
npm install
```

2. Environment setup:

```bash
cp .env.example .env
php artisan key:generate
```

3. Set required values in `.env`:

- `NOTIFICATIONS_API_KEY` (used as `X-API-Key` on protected endpoints)
- `NOTIFICATIONS_PROVIDER_URL` (upstream/mock provider URL, i.e. https://webhook.site/{your-uuid} )

4. Run database setup:

```bash
php artisan migrate --seed
```

5. Start app locally:

```bash
php artisan serve
```

6. Start queue workers (required for delivery):

```bash
php artisan queue:work redis --queue=notifications-high,notifications-normal,notifications-low
```

## Usage

### Postman

A ready collection exists at the project root:

- `postman.json`

It covers health, metrics, notifications, batches, cancel, and retry endpoints.

### Scramble API Docs

Scramble is enabled for `/api/v1` routes.

- UI docs: `/docs/api`
- OpenAPI JSON: `/docs/api.json`

Export options:

1. Download raw OpenAPI JSON directly from `/docs/api.json`.
2. Use that JSON in tools like Swagger Editor/Postman import/codegen.

## Delivery Failure and Recovery

Delivery is retried with increasing back-off delays on temporary failures, such as:

- network/connection failures
- `408`, `429`, `5xx` (`429` respects `Retry-After` when present)

These make Notification `failed` permanently:

- `400`, `401`, `403`, `404`
- Repeatedly trying "retryable" Notification without any success (after 5 attempts)

After a notification ends up as `failed`, recovery is supported via these endpoints:

- `POST /api/v1/notifications/{notification}/retry`
  - Works only for notifications currently in `failed` status.
- `POST /api/v1/notification-batches/{notificationBatch}/retry`
  - Retriggers failed notifications within the batch.

## Logging

There are 3 log channels:

- `api-calls`: request/response-level API logging from controllers
- `notification`: full notification lifecycle logs (creation, delivery service, retry service)
- `notifications_job`: job-level queue execution logs (`DeliverNotification`)

`notification` is the main lifecycle channel to trace a notification from creation to final outcome.

## Testing

Run tests with:

```bash
php artisan test --compact
```

If running inside Docker:

```bash
docker compose exec app php artisan test --compact
```
