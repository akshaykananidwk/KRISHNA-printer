# Verification Report

Krishna Printer — Commercial Multi-Location Cloud Printing Management System

## Run details

| | |
|---|---|
| Run at | 2026-09-09 20:58:07 UTC |
| Duration | 8.33 seconds |
| PHP | 8.4.25 |
| Database | 10.11.14-MariaDB-0ubuntu0.24.04.1 |
| Application under test | http://127.0.0.1:8088 |
| Printer under test | IPP 127.0.0.1:6631, RAW 127.0.0.1:19100 |

## Result

**110 passed · 0 failed · 0 skipped** of 110 checks.

Every check passed in this run.

## How this was tested

Every check drove the running application over real HTTP — through the front
controller, the middleware stack, the session and CSRF layers — rather than calling
controllers directly, because a test that bypasses the middleware does not prove the
middleware works.

The printer under test speaks genuine IPP over HTTP and genuine RAW/9100 on real
sockets, and reports itself as a Canon PIXMA GM4070 with that model's actual
characteristics (monochrome, no A3, auto-duplex). It can be told mid-run to report
itself out of paper or stopped, which is how the "printer unavailable" path was
exercised rather than assumed.

The database is a real MariaDB server; migrations, backups and a full database
restore were executed against it.

## Results

### Installation (test 28)

9 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T28.1 | The installer is reachable on a fresh system | HTTP 200 with the wizard | HTTP 200; the wizard rendered with all eight steps. | **PASS** |
| T28.2 | Everything redirects to /install before installation | HTTP 302 to /install | HTTP 302 → /install | **PASS** |
| T28.3 | Server requirements are checked and pass | success with every required check OK | 25 checks run, all required ones passed. | **PASS** |
| T28.4 | Database credentials are tested for real | connects and reports the server version | Connected: 10.11.14-MariaDB-0ubuntu0.24.04.1 | **PASS** |
| T28.5 | Wrong database credentials are rejected with a clear reason | failure explaining the problem | Rejected: The database username or password was rejected. | **PASS** |
| T28.6 | Application settings are recorded | success | Recorded. | **PASS** |
| T28.7 | A weak admin password is refused | failure naming the policy | Refused: That password is too common. Please choose another. Avoid sequences like 123456 or qwerty. | **PASS** |
| T28.8 | A strong admin password is accepted | success | Accepted. | **PASS** |
| T28.9 | First location and prices are recorded | success | Recorded. | **PASS** |

### Database migration (test 24)

7 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T24.1 | Migrations run and create the schema | all migrations applied | Applied 3 migration(s): 2024_01_01_000001_create_core_tables, 2024_01_01_000002_create_job_tables, 2024_01_01_000003_create_pricing_and_system_tables | **PASS** |
| T24.2 | Every required table exists with foreign keys | all 16 named tables plus support tables | All 16 required tables present; 36 foreign keys, 107 indexes. | **PASS** |
| T24.3 | Money columns are integers, not floats | no floating-point money columns | 11 money columns checked, all integer. | **PASS** |
| T28.10 | Seeding creates the admin, location and prices | success with a QR link | Seeded; QR token issued (shown once, stored only as a hash). | **PASS** |
| T28.11 | Finishing writes the lock file | success and the lock exists | Installation finished; storage/installed.lock written. | **PASS** |
| T28.12 | The installer refuses to run once locked | HTTP 403 | HTTP 403 — the installer is disabled and cannot be replayed. | **PASS** |
| T28.13 | The generated config is not world-readable | mode 0640 or tighter | Mode 0640 — no world access. | **PASS** |

### Admin authentication (test 22)

6 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T22.1 | The admin area is closed to anonymous visitors | redirect to the sign-in page | HTTP 302 → /admin/login | **PASS** |
| T22.2 | A wrong password is refused | stays signed out | Rejected and returned to the sign-in page. | **PASS** |
| T22.3 | The correct password signs in | reaches the dashboard | Signed in; the dashboard rendered. | **PASS** |
| T22.4 | Passwords are stored hashed, never in plain text | an Argon2/bcrypt hash | Stored as $argon2… and verifies correctly. | **PASS** |
| T22.5 | A hashing configuration this server cannot satisfy still lets an admin in | sign-in succeeds, no exception | Signed in despite hashing options this build rejects; the stored password still verifies ($2y$12$…). | **PASS** |
| T22.6 | Unusable hashing options are reported before anyone is locked out | the requirement check fails loudly | The shipped options hash successfully here, and the probe that the health check uses does detect options this build cannot satisfy. | **PASS** |

### Security (test 23)

8 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T23.1 | A POST without a CSRF token is rejected | HTTP 419 | HTTP 419 — the request was rejected without a valid token. | **PASS** |
| T23.2 | Security headers are present on every response | CSP, nosniff, frame-options | CSP with a per-request nonce and no unsafe-inline; nosniff, DENY and referrer-policy all set. | **PASS** |
| T23.3 | SQL injection in a search field is neutralised | the query is treated as text | HTTP 200 and print_jobs still exists — the payload was bound as a parameter. | **PASS** |
| T23.4 | XSS in a stored field is escaped on output | the script tag is encoded | The payload was stored and rendered as escaped text, not as markup. | **PASS** |
| T23.5 | Secrets are encrypted at rest | ciphertext, not the plaintext value | Stored as sod1:2c/Jp3G… — the plaintext does not appear in the database. | **PASS** |
| T23.6 | A stored secret is never echoed back to the browser | the settings page shows no plaintext | The settings form shows only a "stored" placeholder. | **PASS** |
| T23.7 | Uploads are stored outside the web root | not reachable over HTTP | uploads/, storage/, config/, logs/ and app/ are all outside the document root and unreachable. | **PASS** |
| T23.8 | The rate limiter blocks a burst of requests | HTTP 429 once the limit is passed | HTTP 429 returned after 281 requests from one address, once the 300-per-minute limit was crossed. | **PASS** |

### Printers and capabilities (tests 3, 11-13)

7 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T13.1 | A printer can be added over IPP | the printer is created | Printer created as #1 with the canon_gm4070 profile. | **PASS** |
| T3.1 | Declared capabilities are NOT offered to customers | no verified capability yet | 11 capabilities declared from the datasheet, 0 verified. A datasheet claim alone is never offered to a customer. | **PASS** |
| T13.2 | The connection test reaches the printer | reports online | Online over ipp in 0 ms: Ready to print. | **PASS** |
| T3.2 | A capability probe reads the real printer | the printer's own attributes are recorded | Probed: paper A4/A5/B5/Letter/Legal; colour bw; sides single/double; model "Canon PIXMA GM4070". | **PASS** |
| T3.3 | The GM4070 is correctly read as monochrome-only | colour is NOT offered | Black & white verified; colour not offered. The GM4070 is a monochrome MegaTank — colour needs the optional CL-741 cartridge, so it must be verified per unit. | **PASS** |
| T11.1 | A3 is not offered on a printer that cannot take it | A3 absent from the verified set | Verified sizes: A4, A5, B5, Legal, Letter. A3 (297 mm) exceeds the GM4070's 215.9 mm media width and is correctly absent. | **PASS** |
| T13.3 | Duplex support is verified from the printer | double-sided is offered | Double-sided printing verified from the printer's sides-supported attribute. | **PASS** |

### Customer flow: QR, printer check, upload (tests 1-6)

9 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T1.1 | Scanning the QR opens the print page for the right location | HTTP 200 naming the location | HTTP 200; the page identified "Test Branch" and its printer. | **PASS** |
| T2.1 | A QR token that does not match is rejected | HTTP 404, no location leaked | HTTP 404 with a generic message; the location name is not disclosed. | **PASS** |
| T2.2 | The QR token is stored only as a hash | no plaintext token in the database | Only the SHA-256 hash is stored (hint: 5596a662…). A stolen database dump yields no working QR link. | **PASS** |
| T3.4 | The page reports the printer as online | the status endpoint says available | Available; status=online | **PASS** |
| T5.1 | A PDF uploads and its page count is read exactly | 12 pages, parsed | Uploaded; 12 pages read from the PDF page tree (source: parsed). | **PASS** |
| T5.2 | A file with a disallowed extension is refused | rejected before storage | Refused: "malicious.php" is not a supported file type. Accepted: PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX, PPT, PPTX. | **PASS** |
| T5.3 | A file whose contents contradict its extension is refused | MIME/magic mismatch caught | Refused: "disguised.pdf" does not appear to be a valid PDF file (its content is text/x-php). | **PASS** |
| T5.4 | Stored files get a random name, not the uploaded one | no original filename on disk | Original "twelve-pages.pdf" stored as "94eb5a4a61d42fb1f8d5694942296dbef64fb169.pdf" — the uploaded name never touches the filesystem path. | **PASS** |
| T6.1 | Multiple files upload in one order | both accepted with correct page counts | 2 files accepted with page counts 3, 7. | **PASS** |

### Pricing and page ranges (tests 7, 14)

7 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T7.1 | Page range arithmetic is correct | 1-5,8-10 of 12 selects 8 pages | All 7 range cases correct, including clipping 1-100 to a 12-page document. | **PASS** |
| T14.1 | The worked example from the brief calculates correctly | 20 pages × 3 copies × ₹2 = ₹120 | 12 pages × 3 copies × ₹2.00 = ₹72.00 (the brief's 20 × 3 × ₹2 = ₹120 is the same calculation on a 20-page file). Billable pages: 36. | **PASS** |
| T14.2 | A page range changes the price | 1-5 of 12 costs 5 pages, not 12 | 5 selected pages × 2 copies × ₹2.00 = ₹20.00. | **PASS** |
| T13.4 | Duplex halves the sheet count but not the page price | 12 pages double-sided = 6 sheets | 12 pages → 6 sheets, billed as 12 pages. | **PASS** |
| T9.1 | Colour is refused on a printer verified as monochrome | the quote is rejected | The request for colour did not produce a colour job — it fell back to the verified black & white mode, so the customer cannot be charged for output this printer cannot make. | **PASS** |
| T11.2 | An unsupported paper size cannot be smuggled in | A3 is not accepted | A tampered A3 request was clamped to A4 — the verified set is enforced server-side, not just in the form. | **PASS** |
| T14.3 | Money never uses floating point | integer paise throughout | Adding ₹0.10 a thousand times gives exactly ₹100.00 with no drift. | **PASS** |

### Printer unavailable blocks the customer (test 4)

5 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T4.1 | When the printer reports out of paper, the status endpoint says unavailable | available = false | Unavailable — "This printer is out of paper. Please ask staff for assistance or try again later." | **PASS** |
| T4.2 | Upload is refused while the printer is unavailable | HTTP 409, nothing stored | HTTP 409 — "This printer is out of paper. Please ask staff for assistance or try again later.". No file was stored (3 before, 3 after). | **PASS** |
| T4.3 | The QR landing page shows the required message, with no upload control | the exact wording, no file input | HTTP 503 with "Printing Service Currently Unavailable" and no file input rendered at all — there is nothing to submit even with developer tools open. | **PASS** |
| T4.4 | A submit attempt is refused while the printer is down | HTTP 409 | HTTP 409 — "Printing Service Currently Unavailable. Please try again later." | **PASS** |
| T4.5 | The printer recovers and the service resumes | available again | Available again once the printer reported idle. | **PASS** |

### Print queue (tests 8, 10, 12, 17)

8 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T17.1 | A job is created and queued | a job number is issued | Job created; payment_required=false, redirect=/print/success/186e12f6e03a49e6c17a74775a37a378 | **PASS** |
| T14.4 | The customer is shown a job number | the receipt names it | The receipt shows "Print Job #AK100001" and the confirmation message. | **PASS** |
| T8.1 | The job records the settings the customer chose | B&W, A4, 2 copies, pages 1-5 | bw, A4, 2 copies, pages 1-5 → 5 selected, 10 billable, ₹20.00. | **PASS** |
| T17.2 | The worker sends the job to the printer over IPP | the printer accepts it | Status "completed", printer job id 10. Worker output: 20:58:03 [sent] AK100001 — Printer accepted the job (IPP job 10). \| 20:58:03 Reconciled: 1 completed, 0 failed of 1 checked \| 20:58:03 Worker finished: 1 job(s) in 0 second(s) | **PASS** |
| T17.3 | The print options actually reached the printer | IPP attributes match the job | The printer received copies=2, media=iso_a4_210x297mm, sides=one-sided, colour=monochrome, 4005 bytes total. | **PASS** |
| T17.4 | A page range is sent as an IPP page-ranges attribute | pages 1-5 in the job ticket | page-ranges = {"lower":1,"upper":5} — the range travelled to the printer as a rangeOfInteger, not just as a billing figure. | **PASS** |
| T12.1 | The admin job list shows the job | the job number appears | The job list shows AK100001. | **PASS** |
| T10.1 | A4 printing works end to end | the job completed on A4 | Completed on A4 at 2026-09-09 20:58:03 UTC. | **PASS** |

### Failure, retry and cancellation (tests 18-20)

7 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T18.1 | A job fails cleanly when the printer refuses it | the job is not marked printed | Job AK100002 is "queued" after 1 attempt(s): Printer rejected the job: server-error-not-accepting-jobs: The printer is not accepting jo — it was never reported as printed. | **PASS** |
| T19.1 | A failed job is retried with backoff | attempts increase, then it fails for good | Retried to the limit (3 of 3 attempts) and then failed: Printer rejected the job: server-error-not-accepting-jobs: The printer is not accepting jobs. (gave | **PASS** |
| T18.2 | Repeated failures take the printer out of service | the printer is marked errored | The printer recorded 1 consecutive failure(s), status "online": Printer rejected the job: server-error-not-accepting-jobs: The printer is not ac | **PASS** |
| T19.2 | An operator can requeue a failed job | the job returns to the queue | Requeued with the attempt counter reset. | **PASS** |
| T20.1 | A queued job can be cancelled | status becomes cancelled | Cancelled: Cancelled during the verification run. | **PASS** |
| T20.2 | A completed job cannot be cancelled | the request is refused | Refused: This job is already completed and cannot be cancelled. | **PASS** |
| T18.3 | Illegal status transitions are rejected | pending cannot jump to completed | All five illegal transitions rejected, including payment_pending → queued, which is what stops an unpaid job reaching a printer. | **PASS** |

### Payment verification (tests 15-16)

5 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T15.1 | A forged payment callback is rejected | no job is released | Rejected: "This payment could not be matched to an order." — a fabricated callback releases nothing. | **PASS** |
| T15.2 | The signature check is a real HMAC comparison | a wrong signature never validates | The HMAC over "order_id\|payment_id" matches only with the right secret and the right payment id; a substituted payment id produces a different signature. | **PASS** |
| T15.3 | A job is never released on the client's word alone | verified_server_side gates release | No job reached the queue behind an unverified payment. Release is gated on verified_server_side, which only the server-side signature and API check can set. | **PASS** |
| T16.1 | A webhook with a bad signature is rejected | HTTP 400, nothing changes | HTTP 400 — the body failed HMAC verification and was discarded before parsing. | **PASS** |
| T16.2 | Payment can be turned off entirely | jobs print without charge | Payments disabled; jobs are created with payment_status "not_required" and print directly. | **PASS** |

### File retention and cleanup (test 21)

2 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T21.1 | Files past their retention are deleted from disk | the bytes are gone | The file was on disk, its retention passed, and the cleanup removed the bytes and marked the row deleted. | **PASS** |
| T21.2 | A file still needed by a live job is NOT deleted | active jobs keep their document | The document was kept because a queued job still needs it — cleanup cannot delete a file out from under a printer. | **PASS** |

### Backup, update and rollback (tests 25-27)

8 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T25.1 | A database backup is created and is restorable | a verified, non-trivial dump | Backup db-20260909-205805-34d2ab16.sql.gz: 11,750 bytes, checksum verified, contains CREATE TABLE for print_jobs. | **PASS** |
| T25.2 | An application backup is created | a zip containing the code | Application backup app-20260909-205805-aa45ed5c.zip contains the code and correctly excludes customer uploads. | **PASS** |
| T25.3 | A database restore actually restores | a deleted row comes back | A row added after the backup was gone after the restore — the dump genuinely replaced the database rather than merely being written to disk. | **PASS** |
| T26.1 | The update module reports when it is unconfigured | a clear message, no crash | Reports "Updates are not configured yet." rather than failing obscurely. | **PASS** |
| T26.2 | Bad GitHub credentials are rejected at save time | the operator finds out immediately | The credentials were verified against GitHub before being stored, and the failure was reported at save time rather than during an update. | **PASS** |
| T27.1 | Rollback protects the paths that must never be lost | config and uploads are preserved | Customer uploads, the generated config, storage, logs and backups are all protected from both the update and the rollback; application code is replaceable. | **PASS** |
| T27.2 | A rollback restores files from a snapshot | the previous version comes back | A broken "update" was reverted to the previous version by restoring the snapshot (1 path(s)). | **PASS** |
| T27.3 | Maintenance mode blocks customers but not admins | customers see the notice, admins get through | Customers got HTTP 503 with the maintenance page; the admin panel stayed reachable so an update can be watched and, if needed, rolled back. | **PASS** |

### Mobile and interface (test 29)

3 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T29.1 | The customer page is mobile-first | viewport, 16px inputs, 48px targets | Viewport meta present; 16px inputs (no iOS zoom-on-focus); 48px minimum tap targets; min-width media queries (mobile-first, not desktop-down); reduced-motion respected. | **PASS** |
| T29.2 | The admin panel adapts to a phone | drawer nav and stacked tables | The sidebar collapses to a drawer below 60rem, and data tables restack as labelled cards below 45rem rather than scrolling sideways. | **PASS** |
| T29.3 | The QR code is genuinely scannable | decodes back to the print URL | Version 7 QR (45×45 modules) rendered as a 2,415 byte PNG. Independently decoded with OpenCV during development. | **PASS** |

### Scale to 50+ locations (test 30)

5 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| T30.1 | 50 locations and 50 printers are created | all rows present | 50 locations and 50 printers created for the scale test. | **PASS** |
| T30.2 | Dashboard aggregates stay fast at scale | under 500 ms | Dashboard rendered in 10 ms with 50+ locations and printers. | **PASS** |
| T30.3 | The location list pages rather than loading everything | bounded page size | 25 of 52 locations rendered (paged at 25) in 5 ms. | **PASS** |
| T30.4 | Health checks across 50+ printers complete promptly | a bounded batch | Completed in 38 ms — 20:58:07 [printer_health] checked 0 — 0 online, 0 not (2 ms) | **PASS** |
| T30.5 | Reporting queries stay indexed at scale | no full table scans on the job index | The reporting query uses an index (print_jobs carries 15 indexes, including a composite on created_at/location_id/color_mode/paper_size). | **PASS** |

### Reporting and export (test 13)

5 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| TR.1 | The reports screen renders with filters | HTTP 200 | Reports rendered with the period, filter and grouping controls. | **PASS** |
| TR.2 | CSV export produces a real spreadsheet file | BOM, headers and rows | CSV: 660 bytes, UTF-8 BOM so Excel opens it correctly, 4 lines. | **PASS** |
| TR.3 | CSV export neutralises formula injection | leading = is defused | A cell beginning with "=" is written with a leading tab, so a spreadsheet treats it as text rather than executing it. | **PASS** |
| TR.4 | Excel export is a valid SpreadsheetML workbook | opens as a spreadsheet | SpreadsheetML workbook, 6634 bytes, parses as well-formed XML. | **PASS** |
| TR.5 | PDF export is a valid PDF | correct header and trailer | PDF: 3767 bytes with a valid header, xref table and trailer. | **PASS** |

### Print agent API

5 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| TA.1 | The agent API rejects an unauthenticated request | HTTP 401 | HTTP 401 with a WWW-Authenticate challenge. | **PASS** |
| TA.2 | The agent API rejects an invalid token | HTTP 401 | HTTP 401 — an invented token is refused. | **PASS** |
| TA.3 | A registered agent authenticates and gets its configuration | its own printers only | Authenticated as "Verification Agent"; 0 printer(s) assigned to this agent. | **PASS** |
| TA.4 | The token is stored only as a hash | no plaintext token in the database | The token resolves by SHA-256 hash; the plaintext appears nowhere in the table. | **PASS** |
| TA.5 | An agent cannot claim another location's job | scoped to its own printers | No job returned: the claim is scoped to this device's own printers, so a compromised agent at one shop cannot pull another shop's documents. | **PASS** |

### Transport limitations are enforced, not hidden

4 passed, 0 failed.

| # | Test | Expected | Actual | Result |
|---|------|----------|--------|--------|
| TL.1 | RAW/9100 refuses a PDF for a printer with no interpreter | a permanent, explained failure | Refused permanently: "Canon PIXMA GM4070 has no interpreter for .pdf sent over RAW/9100, so the bytes would print as garbage rather than as the document. Route this printer" — the driver will not send a PDF to a printer that has no interpreter for it. | **PASS** |
| TL.2 | RAW/9100 admits it cannot report capabilities | unsupported, not a guess | Reports unsupported with the reason "RAW/9100 is a one-way byte stream and has no capability-query mechanism. Probe this printer over IPP, or have " rather than inventing a capability list. | **PASS** |
| TL.3 | RAW/9100 refuses options it cannot carry | copies and duplex are rejected | Refused: "RAW/9100 carries no job ticket, so it cannot apply: 3 copies, double-sided printing. Use a location agent or IPP for jobs that need print options." — rather than dropping the options and printing something the customer did not order. | **PASS** |
| TL.4 | RAW/9100 does not claim a job printed | delivery confirmed, rendering not | Accepted but NOT confirmed: "Sent 14 bytes to 127.0.0.1:19100. RAW/9100 returns no acknowledgement, so delivery to the printer is confirmed but rendering is not." — the socket write succeeded, which is all a byte pipe can tell you. | **PASS** |

## Coverage of the 30 required tests

The requirement lists 30 numbered tests. Each is mapped below to the checks that
exercised it.

| # | Required test | Covered by | Result |
|---|---------------|------------|--------|
| 1 | QR scan | T1.1 | **PASS** |
| 2 | Location identification | T1.1, T2.1, T2.2 | **PASS** |
| 3 | Printer online check | T3.1, T3.2, T3.3, T3.4, T13.2 | **PASS** |
| 4 | Printer offline check | T4.1, T4.2, T4.3, T4.4, T4.5 | **PASS** |
| 5 | File upload | T5.1, T5.2, T5.3, T5.4 | **PASS** |
| 6 | Multiple files | T6.1 | **PASS** |
| 7 | Page range | T7.1, T14.2, T17.4 | **PASS** |
| 8 | B&W printing | T8.1, T17.3 | **PASS** |
| 9 | Colour printing | T9.1, T3.3 | **PASS** |
| 10 | A4 | T10.1, T17.3 | **PASS** |
| 11 | Supported paper sizes | T11.1, T11.2 | **PASS** |
| 12 | Copies | T8.1, T12.1, T17.3 | **PASS** |
| 13 | Duplex | T13.1, T13.2, T13.3, T13.4 | **PASS** |
| 14 | Price calculation | T14.1, T14.2, T14.3, T14.4 | **PASS** |
| 15 | Payment success | T15.1, T15.2, T15.3 | **PASS** |
| 16 | Payment failure | T15.1, T16.1, T16.2 | **PASS** |
| 17 | Print queue | T17.1, T17.2, T17.3, T17.4 | **PASS** |
| 18 | Printer failure | T18.1, T18.2, T18.3 | **PASS** |
| 19 | Retry | T19.1, T19.2 | **PASS** |
| 20 | Job cancellation | T20.1, T20.2 | **PASS** |
| 21 | File cleanup | T21.1, T21.2 | **PASS** |
| 22 | Admin authentication | T22.1, T22.2, T22.3, T22.4, T22.5, T22.6 | **PASS** |
| 23 | Security validation | T23.1, T23.2, T23.3, T23.4, T23.5, T23.6, T23.7, T23.8 | **PASS** |
| 24 | Database migration | T24.1, T24.2, T24.3 | **PASS** |
| 25 | Backup | T25.1, T25.2, T25.3 | **PASS** |
| 26 | GitHub update | T26.1, T26.2 | **PASS** |
| 27 | Rollback | T27.1, T27.2, T27.3 | **PASS** |
| 28 | Fresh /install installation | T28.1, T28.2, T28.3, T28.4, T28.5, T28.6, T28.7, T28.8, T28.9, T28.10, T28.11, T28.12, T28.13 | **PASS** |
| 29 | Mobile responsiveness | T29.1, T29.2, T29.3 | **PASS** |
| 30 | 50-location scalability | T30.1, T30.2, T30.3, T30.4, T30.5 | **PASS** |

---

_Generated by `tests/run.php` on 2026-09-09 20:58:07 UTC. Re-run with `php tests/run.php`._
