# Campaign Manager — Technical Trial

## Overview

This is a partially built Laravel application for managing email campaigns. It was developed quickly and is considered working but not production-ready.

Your task has two parts:

1. **Review and fix** the existing codebase
2. **Build the missing API layer** from scratch

## Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan queue:work
php artisan schedule:work
# to run tests:
php artisan test
```

## What exists (if not, create to work)

- Models: `Contact`, `ContactList`, `Campaign`, `CampaignSend`
- A `CampaignService` for dispatching campaigns
- A `SendCampaignEmail` Job
- A scheduled command that dispatches due campaigns
- Middleware: `EnsureCampaignIsDraft`

## Part 1 — Code Review

Review everything that exists: migrations, models, service, job, middleware, scheduler.

For each problem you find, document it in `CHANGES.md`:

- What the issue is
- Why it matters in production
- How you fixed it

No hints on how many issues exist or where they are.

## Part 2 — Build the API

Implement a RESTful JSON API with the following endpoints:

### Contacts

- `GET /api/contacts` — paginated list
- `POST /api/contacts` — create (name, email, status)
- `POST /api/contacts/{id}/unsubscribe` — mark as unsubscribed

### Contact Lists

- `GET /api/contact-lists` — list all
- `POST /api/contact-lists` — create
- `POST /api/contact-lists/{id}/contacts` — add a contact to a list

### Campaigns

- `GET /api/campaigns` — list with send stats
- `POST /api/campaigns` — create (subject, body, contact_list_id, scheduled_at)
- `GET /api/campaigns/{id}` — show with send stats
- `POST /api/campaigns/{id}/dispatch` — dispatch immediately

### Requirements

- Use FormRequest classes for validation
- No authentication required
- Pagination on list endpoints
- Stats (pending/sent/failed counts) must use DB aggregation, not collection counting

## Deliverables

- Fixed codebase with `CHANGES.md`
- Working API
- At least one Feature test

Time estimate: 2–3 hours. Use any tools you normally use.

## Questions?

Open an issue in this repository.
