# Krishna Printer

A commercial, multi-location cloud printing management system. A customer scans
the QR code on a printer, uploads a document from their phone, picks their
options, pays if the shop charges, and collects the print. An operator runs 50+
shops and 50+ printers from one admin panel.

Built on PHP 8.1+ and MySQL/MariaDB with no third-party runtime dependencies —
no Composer, no build step. It deploys by uploading files and opening `/install`.

---

## What it does

**For the customer** — one page, no account, mobile first:

1. Scan the QR sticker on the printer.
2. The site checks that *this* printer is reachable and ready, live. If it
   is not, the page says so and offers no upload control at all.
3. Upload one or more documents (PDF, JPG, PNG, DOC/DOCX, XLS/XLSX, PPT/PPTX).
4. Choose copies, colour or B&W, paper size, orientation, page range and duplex
   — offered only where that printer's support has actually been verified.
5. See the price broken down: pages, copies, mode, size, subtotal, tax, gateway
   fee, total.
6. Pay, if the shop has payment enabled.
7. Get a job number — `Print Job #AK102548 created successfully.`

**For the operator:**

- Dashboard with live counters and charts across the whole estate.
- Locations: create, edit, assign printers, set prices, generate and print QR
  codes, rotate a QR token if a sticker is compromised.
- Printers: add, enable, test the connection, probe capabilities, record a
  physical test print, read error logs and history.
- Jobs: watch the queue, retry, cancel, inspect every state change.
- Reports: daily, weekly, monthly or custom range, filtered, exported to CSV,
  Excel or PDF.
- One-click update from GitHub, with a snapshot taken first and automatic
  rollback if anything fails.

---

## Getting started

Requirements, the `/install` wizard and the post-install checklist are in
**[docs/INSTALL.md](docs/INSTALL.md)**.

The short version:

```bash
git clone <your-fork> krishna-printer
# point the web root at krishna-printer/public
# open https://your-domain/install
```

The wizard checks the server, tests your database credentials, runs the
migrations, creates the first admin and location, writes `config/config.php`,
and locks itself when it finishes.

---

## How printing actually works

This is the part that most matters, so it is stated plainly here and in full in
**[docs/PRINTING.md](docs/PRINTING.md)**.

Printers are **not** exposed to the internet. There is no port forwarding, no
inbound firewall rule, and no permanent Windows machine at each shop. Instead
each location runs a small **print agent** — a Python script on any Linux box
already there (a Raspberry Pi is plenty) — which polls the server over outbound
HTTPS, pulls jobs assigned to its own printers only, and hands them to CUPS on
the local network.

The server can also speak **IPP**, **RAW/9100** and **LPD/LPR** directly, for
printers on a network the server can reach. Those drivers are honest about what
they cannot do: the RAW driver refuses a PDF for a printer with no PostScript or
PCL interpreter rather than spraying raw bytes at it, and refuses copies, duplex,
colour or page ranges that the protocol cannot carry.

### Canon PIXMA GM4070

The brief named this printer, so its real capabilities were established before
anything was built on them. Summary:

| | |
|---|---|
| Colour | **No.** Monochrome MegaTank. Colour needs the optional CL-741 cartridge. |
| A3 | **No.** Maximum media width is 215.9 mm. |
| Duplex | Yes, automatic, on A4 / A5 / B5 / Letter. |
| Page description language | **None.** No PostScript, no PCL. |
| Protocols | IPP, RAW/9100, LPD |

Because it has no interpreter, a PDF cannot be sent to port 9100 and expected to
print — it must be rasterised first. That is why the location agent (which has
CUPS and the Canon driver) is the supported path for this model. The full
reasoning, and what each transport can and cannot do, is in
[docs/PRINTING.md](docs/PRINTING.md).

### Nothing is offered until it is verified

Every capability carries its source:

- `declared` — from the datasheet. Recorded for reference and **never offered to
  a customer.**
- `probed` — the printer itself reported it over IPP.
- `manual` — an operator ran a test print and confirmed paper came out.

The options a customer sees are drawn only from `probed` and `manual`. A printer
that has never been probed offers nothing and takes no jobs, which is the safe
failure: better an unavailable printer than a customer paying for A3 on a
machine that tops out at A4.

---

## Documentation

| Document | What is in it |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | Requirements, the wizard, post-install hardening |
| [docs/PRINTING.md](docs/PRINTING.md) | Transports, the agent, the GM4070, documented limitations |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layout, services, request lifecycle, data model |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Cron, the worker, backups, updates, rollback, monitoring |
| [docs/SECURITY.md](docs/SECURITY.md) | The security model and how each control is enforced |
| [docs/VERIFICATION_REPORT.md](docs/VERIFICATION_REPORT.md) | Generated by the test suite from a real run |

---

## Testing

The suite drives a running application over real HTTP — through the front
controller, the middleware, the session and CSRF layers — against a real
MariaDB and a printer that speaks genuine IPP and RAW/9100 on real sockets.

```bash
php tests/MockPrinter.php --ipp-port=6631 --raw-port=19100 --spool=/tmp/mockspool &
php -S 127.0.0.1:8088 -t public public/index.php &
php tests/run.php --url=http://127.0.0.1:8088 --mock-spool=/tmp/mockspool
```

It writes `docs/VERIFICATION_REPORT.md` from what the run observed, quoting the
actual result of every check. Nothing is described as working there unless its
check passed in that run.

Run it from a clean database and an unlocked installer — it exercises `/install`
end to end as test 28.

---

## Licence

Proprietary. All rights reserved.
