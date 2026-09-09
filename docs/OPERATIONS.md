# Operations

Running the system day to day: the schedule, the queue, backups, updates and
rollback.

---

## The schedule

Two crontab lines are all that is needed:

```cron
* * * * * php /path/to/krishna-printer/bin/worker.php --once >> /path/to/logs/worker.log 2>&1
* * * * * php /path/to/krishna-printer/bin/cron.php         >> /path/to/logs/cron.log 2>&1
```

### `cron.php`

Runs every minute and decides internally what is due, so there is nothing to
keep in sync in crontab.

| Task | Frequency | What it does |
|---|---|---|
| `queue_maintenance` | every minute | Reclaims expired leases so a dead worker cannot strand a job |
| `printer_health` | every minute | Re-probes printers; a stale "online" is what lets a customer pay for a job that cannot print |
| `agent_liveness` | every minute | Marks agents that have stopped heartbeating as offline |
| `reconcile_jobs` | every 2 minutes | Asks printers what became of jobs they accepted |
| `expire_payments` | every 5 minutes | Releases jobs behind abandoned checkouts |
| `purge_files` | every 10 minutes | Deletes customer documents past their retention |
| `prune_history` | nightly, 03:15 | Trims status and session history |
| `nightly_backup` | nightly, 03:30 | Scheduled database backup, and clears old ones |

Force one by hand:

```bash
php bin/cron.php --task=printer_health --force
```

### `worker.php`

Dispatches queued jobs to printers **the server can reach directly** (IPP, RAW
or LPD over a private link). Printers driven by a location agent do not need it
— the agent pulls its own work.

```bash
php bin/worker.php --once      # one pass, for cron
php bin/worker.php --daemon    # long-running, for a supervisor
```

---

## The queue

Statuses: `pending` → `payment_pending` → `paid` → `queued` → `processing` →
`printing` → `completed`, with `failed` and `cancelled` as terminal states.
Every transition is written to `job_events`, so a job's whole history is
readable in Admin → Jobs.

**Claiming is leased.** A worker or agent takes a job with a conditional update
that writes a lease token and expiry. Two workers cannot both win the same row,
and a worker that dies mid-job has its lease expire so the job is offered again.
Nothing is lost; nothing prints twice.

**Retries back off.** A failed job is retried up to the configured maximum with
increasing delay. Repeated failures take the printer out of service rather than
feeding it jobs it cannot do — verified as test `T18.2`.

**Cancellation** is available to the customer while the job is still queued, and
to an operator at any point before it prints.

---

## Backups

Admin → System → Backups, or on the nightly schedule.

- **Database** — a full logical dump, compressed.
- **Application** — a snapshot of the code tree, excluding preserved runtime
  directories.

Restores are exercised, not assumed: test `T25.3` takes a backup, changes the
data, restores, and confirms the change is gone.

Backups live in `backups/`, outside the web root. They contain customer data —
treat them with the same care as the database, and move them off the machine.

---

## Updating from GitHub

Admin → System → Updates. Configure the repository, branch and access token
once; the token is encrypted at rest and never echoed back to the browser
(verified as tests `T23.5` and `T23.6`).

**Check for update** shows the incoming version, commit SHA, message, date,
author and the list of changed files — including a warning if any of them touch
a preserved path.

**Update now** runs, in order:

1. Enter maintenance mode
2. Snapshot the application
3. Back up the database
4. Download the new revision
5. Verify the archive
6. Stage it
7. Swap directories
8. Restore preserved paths
9. Run migrations
10. Clear caches
11. Health check
12. Leave maintenance mode
13. Record the result in `update_logs`

**If any step fails, it rolls back automatically** — the snapshot is restored,
the database is restored, and maintenance mode is lifted. Verified as tests
`T26.1`, `T26.2` and `T27.1`–`T27.3`.

### What is never overwritten

```
config/config.php     .env
uploads/              storage/
logs/                 backups/
public/qr/            storage/installed.lock
```

A preserved directory protects everything inside it, and a preserved file
protects its directory from being removed wholesale. Configured in
`config/updates.php`.

### Maintenance mode

While an update runs, customers see a maintenance page; administrators keep
access so they can watch it happen and intervene. Verified as test `T27.3`.

---

## Monitoring

**Admin → Dashboard** carries live counters and charts across the estate.

**Admin → Printers** shows each printer's status, last seen, last successful
print and last error.

**Admin → Print agents** shows each agent's last heartbeat. An agent that stops
reporting has its printers marked offline within a minute, and they stop being
offered to customers.

**Logs** are in `logs/`. The audit trail — who changed what, and when — is in
Admin → System → Activity, backed by `activity_logs`.

### When a printer goes offline

1. Admin → Printers → the printer → **Test connection**. The error is reported
   verbatim, not flattened to "failed".
2. If it is agent-driven, check the agent is heartbeating and that CUPS on that
   machine can see the printer (`lpstat -p`).
3. Customers are already protected: availability is probed live at the upload
   gate, so an offline printer stops taking orders on its own.

---

## Rotating a QR token

If a sticker is photographed, defaced or replaced, Admin → Locations → the
location → **Rotate QR**. The old token stops working immediately. Only the hash
of a token is ever stored, so the plaintext is shown once, at generation — print
the new sticker then.
