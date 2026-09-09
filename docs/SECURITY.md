# Security model

What is defended, how, and where each control is verified by the test suite.

---

## Data access

**Every query is a PDO prepared statement.** All SQL lives in the repository
layer; no string interpolation of user input anywhere. Verified as test `T23.3`,
which puts an injection payload through a search field and confirms it is
treated as a search term.

---

## Sessions and authentication

- Cookies are `HttpOnly`, `Secure` (once HTTPS is enforced) and `SameSite=Lax`.
- The session ID is **regenerated on sign-in**, so a fixated session cannot
  survive authentication.
- Passwords use `password_hash()` — Argon2id where the build provides it,
  bcrypt at cost 12 otherwise, configured in `config/security.php` — never a
  home-made scheme. Verified as test `T22.4`.
- Argon2's `threads` stays at 1: PHP's Argon2 comes from libargon2 or libsodium
  depending on the build and the sodium one rejects anything higher. A hashing
  configuration this server cannot satisfy is logged and falls back to bcrypt
  rather than refusing a correct password — re-hashing happens *after*
  verification and must never turn a valid sign-in into a failure. Verified as
  tests `T22.5` and `T22.6`.
- The password policy rejects the short, the obvious, and anything guessable
  from context such as the admin's own email or the business name. Verified as
  tests `T28.7` and `T28.8`.
- Repeated failures lock an account for a cooling-off period; failures are
  counted server-side per account, not per browser.
- Roles are enforced in middleware, before a controller runs — not by hiding
  links in a template.

---

## CSRF

Every state-changing request carries a token. Tokens are **masked per request**
(a random pad XORed with the session secret), so a token captured from one
response cannot be replayed and the same secret never appears twice on the wire.
Verified as test `T23.1`: a POST with a valid session but no token is rejected.

---

## Output and content

- Everything rendered is escaped at the point of output.
- The Content-Security-Policy is **nonce-based with no `unsafe-inline`.** No
  inline event handlers, no third-party scripts. This is why charts are rendered
  as server-side SVG rather than pulled from a charting CDN.
- `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and HSTS are
  set on every response. Verified as test `T23.2`.

CSV exports are protected against formula injection — a cell beginning `=`, `+`,
`-` or `@` is neutralised so opening a report in Excel cannot execute anything.
Verified as test `TR.3`.

---

## File uploads

A customer upload is hostile until proven otherwise:

1. The extension must be on the allow-list.
2. The **MIME type is read from the file's contents**, not from the browser's
   claim, and must agree with the extension. A `.pdf` that is actually something
   else is refused — verified as test `T5.3`.
3. Size is capped.
4. The file is stored under a **randomised name** in a hashed directory, so the
   original name never reaches the filesystem and cannot be guessed. Verified as
   test `T5.4`.
5. Storage is **outside the web root** — there is no URL that reaches an upload.
   Verified as test `T23.7`.
6. Files are deleted automatically once past their retention period. Verified as
   tests `T21.1` and `T21.2`.

Documents are never executed, never included, and never passed to a shell.

---

## Secrets

**No plaintext password or API secret is ever stored.**

| Secret | How it is kept |
|---|---|
| Admin passwords | `password_hash()` |
| QR tokens | SHA-256 hash. Plaintext shown once, at generation. |
| Agent and API tokens | SHA-256 hash. Plaintext shown once, at registration. |
| Gateway keys, GitHub tokens | Encrypted at rest with XChaCha20-Poly1305 (libsodium) or AES-256-GCM |

The application key lives in `config/config.php`, mode `0600`, outside the web
root, excluded from version control and preserved across updates.

A stored secret is never echoed back to a browser — the settings screens show
whether a secret is set, not what it is. Verified as tests `T23.5` and `T23.6`.

---

## Payment

> Never print because the client says the payment succeeded.

Verification is server-side and has two independent parts:

1. **Signature** — HMAC-SHA256 over `order_id|payment_id`, compared with
   `hash_equals()`.
2. **Gateway re-fetch** — the payment is fetched from the gateway's API and must
   come back `status = captured`, for the expected order, for the exact amount.

Only then is the job released to print. `verified_server_side` is set with a
conditional update, so a webhook and a browser callback arriving together cannot
release the same job twice. A failed or unverifiable payment prints nothing.
Verified as tests `T15.1`–`T15.3` and `T16.1`–`T16.2`.

---

## Printers and the network

- **No printer port is exposed to the internet.** No forwarding, no DMZ.
- The location agent makes only **outbound** HTTPS connections; nothing inbound
  is opened at a shop.
- An agent's claim is scoped server-side to its own printers, so a compromised
  agent at one location cannot pull another location's documents. Verified as
  test `TA.5`.
- **QR codes never carry printer credentials.** A QR encodes a location code and
  an opaque token, nothing more. The token is stored only as a hash and can be
  rotated at any time. Verified as tests `T2.1` and `T2.2`.
- The customer status endpoint is scoped to the printer that session actually
  landed on, so it cannot be used to enumerate the estate.

---

## Rate limiting

Per-IP and per-action limits sit in middleware, backed by the `rate_limits`
table so they hold across processes. Uploads, sign-in and job submission each
have their own budget. Verified as test `T23.8`, which drives a burst of
requests and confirms the limiter refuses them.

---

## Transport

HTTPS is enforced application-wide once `force_https` is enabled, with HSTS. The
installer detects the scheme it was reached on and defaults accordingly.

---

## Audit

Every administrative action — sign-in, printer change, price change, QR
rotation, update, backup, restore — is written to `activity_logs` with the
actor, the target and a description. The trail is append-only from the
application's point of view: there is no UI that edits or deletes it.

---

## The installer

`/install` is available only while `storage/installed.lock` is absent. Step 8
writes that lock; afterwards `/install` returns 403. Verified as tests `T28.11`
and `T28.12`. The generated config is written mode `0600` — verified as test
`T28.13`.

---

## Reporting a vulnerability

Contact the system owner directly. Do not open a public issue.
