# CHANGES.md by Paulo Neiva

## Overview

This document describes every issue found in the original codebase, why each one matters, and how it was fixed. It also covers the new API layer, test suite, and architecture decisions.

---

## Part 1 — Code Review & Fixes

### 1. Missing Eloquent Models

**Issue:** Factories, migrations and relationships referenced `Contact`, `ContactList` and `CampaignSend`, but the model classes did not exist.

**Why it matters:** The application cannot run without these models.

**Fix:** Created all three models with proper `$fillable` arrays, `$attributes` defaults, `$casts`, and Eloquent relationships (`belongsTo`, `hasMany`, `belongsToMany`).

---

### 2. Inverted Middleware Logic (`EnsureCampaignIsDraft`)

**Issue:** The condition was `$campaign->status === 'draft'`, which blocked draft campaigns and allowed non-draft campaigns to be dispatched — the exact opposite of the intended behaviour.

**Why it matters:** Campaigns in draft status could never be dispatched and, conversely, campaigns that were already sending or sent could be dispatched again.

**Fix:** Changed condition to `$campaign->status !== 'draft'`.

---

### 3. Job Error Handling — Silent Failure (`SendCampaignEmail`)

**Issue:** The `handle()` method wrapped all logic in a `try/catch` that logged the error and returned normally. From the queue worker's perspective, every job "succeeded".

**Why it matters:**

- Laravel's built-in retry mechanism (`$tries`, `$backoff`, `failed()` hook) is never triggered.
- Real email server failures are not retried.
- Failed jobs never appear in the `failed_jobs` table, breaking operational observability.

**Fix:** Removed the `try/catch` block. Added `$tries = 3` and `$backoff = [30, 60, 90]` for automatic retries with delays. Added a `failed()` hook that marks the send as `failed`, logs the error, and checks whether the campaign is complete.

---

### 4. Stats Accessor Loads All Sends into Memory (`Campaign::getStatsAttribute`)

**Issue:** `$this->sends` loaded every `CampaignSend` row into a PHP collection and filtered in-memory to count statuses.

**Why it matters:** A campaign sent to a large contact list (e.g. 100k recipients) would load that many Eloquent models into memory on every API call. This causes memory exhaustion and very slow response times.

**Fix:** Replaced with a single `GROUP BY` query that counts directly in the database. This returns only 3 rows (one per status) regardless of campaign size. The API list endpoint uses `withCount()` subqueries for the same reason.

---

### 5. Contact Dispatch Loads Entire List at Once (`CampaignService`)

**Issue:** `->get()` loaded all active contacts for a list into memory before iterating.

**Why it matters:** A large contact list would exhaust PHP's memory limit and cause the dispatching process to crash.

**Fix:** Replaced `->get()` with `->chunk($chunkSize, ...)`. Contacts are now processed in batches (of 200, configurable), keeping memory flat regardless of list size.

---

### 6. N+1 Query in `SendCampaignEmail` Job

**Issue:** The job accessed `$this->send->contact` and `$this->send->campaign` without eager loading. Each job execution triggered 2 additional queries (one to load the contact, one to load the campaign).

**Why it matters:** With N sends, this adds 2N extra queries to the database during campaign dispatch — a significant performance bottleneck.

**Fix:** Added `$this->send->loadMissing(['contact', 'campaign'])` at the start of `handle()` to batch-load both relationships in a single pass.

---

### 7. `scheduled_at` Stored as `VARCHAR`

**Issue:** The `campaigns` migration defined `scheduled_at` as `$table->string(...)` instead of `$table->timestamp(...)`.

**Why it matters:**

- No date validation at the database level — any input string can be stored.
- Date comparisons in the scheduler query (`<= now()`) can fail if the string format was not absolutely right.

**Fix:** Changed to `$table->timestamp('scheduled_at')->nullable()`. Added `'scheduled_at' => 'datetime'` to the model's `$casts` so Laravel hydrates it as a Carbon instance.

---

### 8. Scheduler — Missing Status Filter / Incorrect Class / Not Started

**Issue (logic):** The scheduler queried `Campaign::where('scheduled_at', '<=', now())` without filtering by `status = 'draft'` or checking `whereNotNull('scheduled_at')`. This means campaigns that were already sending/sent would be dispatched again on every scheduler tick.

**Issue (framework):** `App\Console\Kernel` is a Laravel 9/10 pattern. In Laravel 12, the console kernel is no longer loaded — the schedule defined there is silently ignored.

**Issue (project):** The scheduler also needs to be started. This was not mentioned in the README.md setup section.

**Why it matters:** Without the status filter, every past campaign is re-dispatched every minute. With the wrong class, the scheduler doesn't run at all.

**Fix:** Moved the schedule to `bootstrap/app.php` using `->withSchedule()` (the Laravel 12 pattern). Added `where('status', 'draft')` and `whereNotNull('scheduled_at')` to the query. The old `Kernel.php` schedule is commented out. Added `php artisan schedule:work` to the setup instructions.

---

### 9. Scheduler Runs Service Synchronously

**Issue:** The scheduler called `CampaignService::dispatch()` directly inside the `->call()` closure. For a large campaign, the scheduler process blocks for the entire duration of chunking and job dispatching.

**Why it matters:** While one large campaign is being dispatched, the scheduler cannot process any other scheduled tasks. If the process exceeds the 1-minute tick interval, campaigns can overlap or be skipped.

**Fix:** Created a `DispatchCampaign` job. The scheduler now dispatches this lightweight job (instant return), and the actual contact chunking + job creation happens asynchronously in the queue worker.

---

### 10. Missing Unique Constraints

**Issue:**

- `contacts.email` had no unique constraint — duplicate email addresses could be inserted.
- `contact_contact_list` had no unique constraint on `(contact_id, contact_list_id)` — the same contact could be added to the same list multiple times.

**Why it matters:** Duplicate contacts cause duplicate campaign sends (same person receives the same email multiple times). Duplicate pivot entries multiply the problem.

**Fix:** Added `->unique()` to `contacts.email` and `->unique(['contact_id', 'contact_list_id'])` to the pivot table.

---

### 11. Missing Foreign Key Cascades and Indexes

**Issue:**

- Foreign keys on `campaign_sends` and `contact_contact_list` had no `ON DELETE` behaviour. Deleting a campaign or contact left orphaned rows.
- `campaigns.contact_list_id` had no delete protection — deleting a contact list left campaigns pointing to a non-existent list.
- No indexes on columns used in `WHERE` and `GROUP BY` clauses.

**Why it matters:** Orphaned rows cause data integrity issues, broken joins, and incorrect stats. Missing indexes cause full table scans on queries that run for every API call and every job, causing a significant performance hit.

**Fix:**

- `campaign_sends`: `cascadeOnDelete()` on both FKs — sends are removed with their parent.
- `contact_contact_list`: `cascadeOnDelete()` on both FKs — pivot rows are cleaned up automatically.
- `campaigns.contact_list_id`: `restrictOnDelete()` — prevents accidental deletion of a list that has campaigns.
- Added indexes: `campaigns(status)`, `campaigns(scheduled_at)`, `campaign_sends(campaign_id, contact_id)` (unique composite), `campaign_sends(contact_id)`.
- Removed the auto-increment `id` column from the `contact_contact_list` pivot table — the composite unique key is sufficient as identifier.

---

### 12. Campaign Never Transitions to "sent"

**Issue:** After all `SendCampaignEmail` jobs completed, the campaign remained in `sending` status permanently.

**Why it matters:** Campaign status is broken — users and APIs see an incomplete campaign even though all emails were delivered.

**Fix:** Added `markCampaignSentIfComplete()` to `SendCampaignEmail`. After each send completes (or fails), it counts remaining `pending` sends for the campaign and updates status to `sent` when the count reaches zero. This is also called from the `failed()` hook so the campaign transitions even if some sends fail. This is a business logic rule and can change in the future... Maybe add a new status like `sent_with_failures`.

---

### 13. Idempotency Guard Skips Completion Check

**Issue:** The `handle()` method has an early return when `$this->send->status === 'sent'` (idempotency guard). If the job marked the send as `sent` but crashed before `markCampaignSentIfComplete()` ran, every retry hit the early return — the campaign completion check was never reached.

**Why it matters:** The campaign gets permanently stuck in `sending` status.

**Fix:** Moved the `markCampaignSentIfComplete()` call to execute before the early return, so retries still check and transition the campaign.

---

### 14. Idempotency for `CampaignSend` Creation

**Issue:** If `DispatchCampaign` is retried (e.g. worker restart mid-chunk), sends that were already created would be duplicated.

**Why it matters:** Duplicate `CampaignSend` rows lead to duplicate emails and broken stats.

**Fix:** Used `CampaignSend::firstOrCreate(...)` with the unique pair `(campaign_id, contact_id)` as the lookup key. Combined with the unique composite index, this ensures exactly one send per contact per campaign. The job also checks `$send->status === 'pending'` before dispatching, so already-processed sends are skipped.

---

### 15. Idempotency for `DispatchCampaign` Job

**Issue:** If the `DispatchCampaign` job is retried, nothing prevented it from running the full dispatch cycle again.

**Fix:** Added `$this->campaign->refresh()` at the top of `handle()` to get the latest status from the database, followed by a guard: `if ($this->campaign->status !== 'draft') { return; }`. Once a campaign moves to `sending`, retries are no-ops.

---

## Part 2 — API Layer

### Endpoints Implemented

| Method | Endpoint                           | Description                                   |
| ------ | ---------------------------------- | --------------------------------------------- |
| GET    | `/api/contacts`                    | Paginated contact list                        |
| POST   | `/api/contacts`                    | Create contact (name, email, optional status) |
| POST   | `/api/contacts/{id}/unsubscribe`   | Mark contact as unsubscribed                  |
| GET    | `/api/contact-lists`               | Paginated contact list groups                 |
| POST   | `/api/contact-lists`               | Create contact list                           |
| POST   | `/api/contact-lists/{id}/contacts` | Add contact to list                           |
| GET    | `/api/campaigns`                   | Paginated list with send stats                |
| POST   | `/api/campaigns`                   | Create campaign                               |
| GET    | `/api/campaigns/{id}`              | Show campaign with send stats                 |
| POST   | `/api/campaigns/{id}/dispatch`     | Dispatch campaign (draft only)                |

### Design Decisions

- **FormRequest validation** for all write endpoints — validation logic is separated from controllers and is reusable.
- **Configurable pagination** via `config/pagination.php` (`per_page` default: 20, `max_per_page`: 100). Clients can pass `?per_page=N`, capped at the configured maximum to prevent abuse.
- **DB aggregation for stats** — the campaign list endpoint uses `withCount()` subqueries instead of loading sends. The show endpoint uses a `GROUP BY` accessor. Both avoid N+1 queries.
- **JSON error responses** — all `HttpException`s on `api/*` routes are rendered as JSON via `bootstrap/app.php` exception handler. A `Route::fallback()` returns a 404 JSON response for undefined endpoints.
- **`EnsureCampaignIsDraft` middleware** on the dispatch route prevents dispatching non-draft campaigns at the routing level.

---

## Part 3 — Test Suite

| Test Class        | What's Covered                                                                                                                                  |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `ContactTest`     | List, create, validation, duplicate email, invalid status, unsubscribe, 404                                                                     |
| `ContactListTest` | List, create, validation, add contact, duplicate (409), invalid contact                                                                         |
| `CampaignTest`    | List with stats, create, validation, nonexistent list, past date, show, 404, dispatch, dispatch non-draft (422), unsubscribed contacts excluded |

### Test Philosophy

Tests assert **behaviour**, not implementation:

- Every test sends a real HTTP request (`getJson`, `postJson`) and checks the response shape, status code, and database state.
- Edge cases are covered: validation errors, 404s, 409 conflicts, past `scheduled_at` dates, unsubscribed contacts being excluded from dispatch.
- `RefreshDatabase` trait wraps each test in a transaction that rolls back, ensuring full isolation between tests.

---

## Trade-offs & Future Improvements

- Campaign completion is checked at every send. This can be further improved.
- Add a net **API endpoint** to get the **entity** details for (Contact, Campaign, etc) like: `GET /api/contacts/{id}`
- **Decide** what to do when **deleting** entities - mark as deleted (soft delete) or permanently delete (current behaviour).
- If the API is to be consumed by external applications, **authentication** and **rate limiting** should be implemented.

## Final technical notes

- Project was tested with a MySQL 8 database on my local machine. Also tested with an SQLite file database.
- I added a Postman collection (postman_collection.json) for you to test the API endpoints.
