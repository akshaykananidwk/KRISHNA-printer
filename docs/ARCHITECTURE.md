# Architecture

PHP 8.1+, MySQL/MariaDB, and nothing else at runtime. No Composer, no package
manager, no build step. That is a deliberate constraint: the system must deploy
to ordinary shared hosting by uploading files, and every dependency added is a
dependency the shop owner has to keep patched.

---

## Layout

```
public/           the only web-reachable directory
  index.php       front controller
  assets/         css, js, images
app/
  Core/           framework: router, request, response, container, session,
                  CSRF, database, encrypter, validator, view, migrator, logger
  Http/
    Controllers/  thin — validate, call a service, return a response
    Middleware/   auth, CSRF, rate limiting, security headers, HTTPS, maintenance
  Models/         typed row wrappers
  Repositories/   all SQL lives here
  Services/       the business logic
  Support/        small helpers (Money, PageRange, QrEncoder, Str)
resources/views/  templates
routes/web.php    the routing table
config/           configuration; config.php is installer-generated, never committed
database/
  migrations/     versioned .sql
bin/
  worker.php      dispatches the print queue
  cron.php        health checks, cleanup, scheduled backups
agent/            the location print agent (Python)
tests/            the end-to-end suite and the mock printer
storage/          sessions, cache, temp, update snapshots
uploads/          customer files — outside the web root
```

`public/` is the only directory a web server should expose. Uploads, config,
logs and backups all live above it and are unreachable by URL — verified as test
`T23.7`.

---

## Request lifecycle

```
public/index.php
  → bootstrap/app.php          build the container, load config, register routes
  → Router::match()            method + path → handler, with typed constraints
  → middleware stack           https → security headers → maintenance → session
                               → rate limit → auth → CSRF
  → Controller                 validate input, call services
  → Service                    the actual work
  → Repository                 SQL, always through PDO prepared statements
  → Response                   rendered view or JSON, escaped on output
```

Controllers hold no business logic. A controller validates, delegates, and
shapes a response; anything a second caller might need lives in a service. The
worker and the agent API reach the same services the web UI does, which is why a
job queued from the phone and a job queued by an operator behave identically.

### A note on the router

Routes are matched in registration order, first match wins, with per-parameter
regex constraints (`{id:\d+}`, `{token:[a-f0-9]{32}}`). Two consequences worth
knowing:

- A pattern registered earlier shadows a more specific one registered later.
  `/print/{location}/{token}` would swallow `/print/success/{batch}`, so the QR
  landing route is registered **last** and its `{location}` carries a negative
  lookahead excluding the reserved path segments. `LocationController` refuses
  those same words as location codes, so an operator cannot create a shop whose
  QR is unreachable.
- A constraint is compiled into the route regex verbatim, so only the literal
  parts of a pattern are quoted. A pattern that does not compile, or one whose
  constraint introduces a capturing group (which would shift every parameter
  position), throws at registration rather than silently 404ing at request time.

---

## Services

| Service | Responsibility |
|---|---|
| `PrinterService` | Health, availability, driver selection, status history |
| `PrinterCapabilityService` | Probing, the declared/probed/manual model, customer option lists, server-side re-validation of a selection |
| `PrintQueueService` | Queueing, lease-based claiming, retries, cancellation, state transitions |
| `PricingService` | Integer-paise pricing from location, printer, size, colour, duplex and page count |
| `PaymentService` | Order creation and **server-side** verification |
| `FileService` | Upload validation, randomised storage names, retention, cleanup |
| `DocumentService` | Page counting and format handling |
| `QRCodeService` | Token issue and rotation, QR rendering |
| `HealthCheckService` | Server requirements, printer and agent liveness |
| `BackupService` / `RollbackService` | Snapshots and restores |
| `GitHubUpdateService` | Check, fetch, apply, migrate, roll back |
| `AuthService`, `AuditService`, `RateLimiter` | Sessions and lockout, the audit trail, throttling |

### Printer drivers

`app/Services/Printer/` holds the transport layer behind one `PrinterDriver`
interface, so a printer's protocol is a configuration choice:

- `IppDriver` — a full RFC 8010/8011 client, encoder and decoder written from
  the specification. The only transport that can verify a capability.
- `Raw9100Driver` — a byte pipe, and honest about it.
- `LpdDriver` — RFC 1179.
- `AgentDriver` — hands the job to the location agent's pull queue.

What each can and cannot do, and what is done instead where it cannot, is in
[PRINTING.md](PRINTING.md).

---

## Data model

22 tables. The ones that carry the domain:

| Table | Holds |
|---|---|
| `locations` | Shops, with a hashed QR token per location |
| `printers` | Printers, their profile, live status and error history |
| `printer_connections` | How to reach a printer — kept separate from the printer record so credentials are not loaded with every listing |
| `printer_capabilities` | One row per capability, each with `source` = declared / probed / manual |
| `printer_status_checks` | Every health probe, for the status history |
| `devices` | Registered location agents |
| `print_sessions` | A customer's basket, scoped to a browser session |
| `print_files` | Uploaded files: original name, randomised stored name, hash, page count, retention |
| `print_jobs` | The queue, with lease token and expiry |
| `job_events` | Every state change, append-only |
| `payments` | Gateway orders, with `verified_server_side` |
| `pricing_rules` | Per location / printer / size / colour / duplex |
| `api_tokens` | Agent and API tokens, stored as SHA-256 hashes |
| `admins`, `users` | Operator accounts and roles |
| `activity_logs` | The audit trail |
| `system_settings`, `print_settings` | Configuration, with secrets encrypted at rest |
| `backups`, `update_logs`, `migrations`, `rate_limits` | Operations |

Foreign keys and indexes throughout; verified as test `T24.2`.

**Money is never a float.** Every amount is an integer number of paise, in a
`BIGINT`/`INT` column, and `Money` does the arithmetic and formatting. Floating
point cannot represent ₹0.10 exactly, and a rounding error that shows up once in
ten thousand jobs is a bug nobody can reproduce. Verified as test `T24.3`.

---

## Concurrency

A job is claimed with a conditional `UPDATE` that writes a lease token and
expiry:

```sql
UPDATE print_jobs
   SET status = 'processing', lease_token = ?, lease_expires_at = ?
 WHERE id = ? AND status = 'queued' AND (lease_expires_at IS NULL OR lease_expires_at < UTC_TIMESTAMP())
```

Two workers racing on the same row cannot both win — the second one's `WHERE`
clause no longer matches and it gets zero affected rows. If a worker or agent
dies mid-job, the lease expires and the job is offered again. Nothing is lost
and nothing is printed twice.

The same shape guards payment verification: `verified_server_side` is set with a
conditional update, so only the first caller releases the job for printing even
if the gateway sends a webhook and the browser posts a callback at once.
