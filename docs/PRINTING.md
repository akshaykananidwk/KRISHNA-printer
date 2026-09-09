# Printing: transports, the agent, and what each one cannot do

This document records how documents actually reach paper, what was verified
against a real printer, and — where a feature cannot be delivered through a
given transport — what the limitation is and what is done instead.

---

## 1. The rule that shapes everything

> A printer must never be reachable from the public internet.

Port 9100 has no authentication. It is not "insecure if misconfigured" — it has
no concept of a user. Anyone who can open a TCP connection to it can print,
enumerate the queue, and exhaust the consumables. The same is broadly true of
LPD, and of IPP as most devices ship it.

So there is no port forwarding, no DMZ host and no inbound firewall rule for
printers anywhere in this system. The supported production topology is the
**location print agent**, which only ever dials out.

The second constraint is technical rather than security-driven:

> A consumer inkjet has no page description language.

The Canon PIXMA GM series, like most consumer inkjets, contains no PostScript or
PCL interpreter. It accepts a *raster* prepared by the host driver. A PDF pushed
to port 9100 does not print the document; it prints pages of garbage, or jams.
Rasterising requires a host running the real driver — which means a machine on
the shop's network, which is exactly what the agent is.

Together these mean the agent is not a workaround. It is the only architecture
that satisfies both "do not expose the printer" and "print a PDF on a GM4070",
and it does so without a permanent Windows PC at any location.

---

## 2. The location print agent

`agent/kpms-agent.py` — Python 3 standard library only, plus the CUPS
command-line tools. Install with `agent/install.sh` on any always-on Linux box
on the shop network; a Raspberry Pi is ample.

It runs one loop, entirely outbound over HTTPS:

```
heartbeat  →  "I am alive; here is each printer's state from CUPS"
claim      →  "give me a job for one of my printers"
document   →  download the file
             (render and print locally through CUPS)
status     →  "printing" / "completed" / "failed"
```

**Authentication.** Each agent gets a bearer token, shown once at registration
and stored only as a SHA-256 hash. A claim is scoped server-side to the printers
belonging to that device, so a compromised agent at one shop cannot pull another
shop's documents — verified as test `TA.5`.

**Rendering.** Office formats go through LibreOffice to PDF, then through the
CUPS filter chain with the vendor driver, so what the printer receives is what
that model expects.

**Failure.** If the agent stops heartbeating, its printers go stale and stop
being offered to customers. A job it claimed but never completed has its lease
expire and is re-offered — a crashed agent cannot lose or silently double-print
a job.

---

## 3. Direct transports

For printers on a network the server itself can reach — an office LAN, a private
VLAN, a site-to-site tunnel — three drivers are built in. They are modular:
`PrinterDriver` implementations selected per printer.

### IPP (RFC 8010 / 8011) — preferred

A complete IPP client, encoder and decoder written from the specification.

- **Can:** query real capabilities (`Get-Printer-Attributes`), read live state
  and state reasons (`media-empty`, `media-jam`, `cover-open`, `toner-low`),
  submit jobs with copies, colour mode, media size, orientation, duplex and
  `page-ranges`, and poll job state to completion.
- **Cannot:** make a printer do what it does not support. What the printer
  reports is what is offered.

IPP is the only transport that can *verify* anything, which is why capability
probing is an IPP operation.

### RAW / JetDirect (TCP 9100)

A byte pipe. It carries a stream to the printer and nothing else.

- **Can:** send a file the printer can already interpret.
- **Cannot:** report capabilities, report state, confirm a job printed, or carry
  *any* job option — copies, duplex, colour and page ranges have nowhere to live
  in the protocol.

**What is done instead of pretending:** the driver refuses. It returns
`PrinterCapabilities::unsupported()` rather than guessing; it rejects a PDF
addressed to a printer with no interpreter, with an explanation, rather than
wasting a ream; it rejects any job option it cannot carry rather than silently
dropping it; and it reports `confirmed: false` rather than claiming a job
printed when all it did was close a socket. Verified as tests `TL.1`–`TL.4`.

Copies are refused rather than emulated by sending the document N times. That
emulation is possible and may be added, but it is a different thing from the
printer's own collated copies, and it would be wrong to accept the option under
a name that implies the printer handled it.

### LPD / LPR (RFC 1179)

- **Can:** queue a job with a control file, and carry copies.
- **Cannot:** report capabilities, report meaningful state, or carry duplex,
  colour or page ranges — those are driver-side concepts LPD has no field for.

Treated the same way as RAW: refuse rather than misrepresent.

---

## 4. Canon PIXMA GM4070 — verified characteristics

The brief named this model, so its capabilities were established before any
feature was built on them.

| Characteristic | Finding | Consequence in this system |
|---|---|---|
| Colour | **Monochrome.** The GM4070 is a mono MegaTank; colour requires the optional CL-741 / CL-741XL cartridge. | Colour is not offered on this profile unless a probe or a test print verifies it. |
| A3 | **Not supported.** Maximum media width 215.9 mm. | A3 cannot be offered; verified absent as test `T11.1`. |
| Paper sizes | A4, A5, B5, Letter, Legal | Offered only once probed or manually verified. |
| Duplex | Automatic, on A4 / A5 / B5 / Letter | Offered when the printer reports it (test `T13.3`). |
| Page description language | **None** — no PostScript, no PCL | Must be rasterised by a CUPS host. The agent is the supported path. |
| Protocols | IPP, RAW/9100, LPD | IPP for status and probing; printing goes via the agent. |

This is recorded as the `canon_gm4070` profile in `config/printing.php`, with
`preferred_driver => 'agent'` and `requires_rasterisation => true`.

**The limitation, stated plainly:** the server cannot print a PDF to a GM4070 by
itself. No protocol choice fixes this, because the printer has no interpreter to
address. The safest supported alternative — and the one implemented — is to
render on a CUPS host at the location and print through the vendor driver.

---

## 5. Declared, probed, manual

Every capability row carries where it came from:

| Source | Meaning | Offered to a customer? |
|---|---|---|
| `declared` | From the manufacturer's specification. | **No.** |
| `probed` | The printer reported it over IPP. | Yes. |
| `manual` | An operator ran a test print and confirmed the output. | Yes — and it outranks a probe, because a human watched paper come out. |

A spec sheet is a claim; a claim is not a capability. So a printer that has never
been probed offers nothing and accepts no jobs. That is deliberate: an
unavailable printer costs a customer a minute, while a printer that accepts
payment for A3 it cannot produce costs a refund and the shop's reputation.

Verified as tests `T3.1` (declared values are not offered), `T3.2` (a probe reads
the real printer) and `T3.3` (the GM4070 reads as monochrome-only).

---

## 6. Availability is checked live, never from cache

Before a customer can upload, and again before a job is queued, the printer is
probed **as it is now**. There is a cache for display surfaces, but every gate
that takes a customer's file, money or paper opts out of it and probes fresh.

A cached "online" from fifty seconds ago is not evidence that the machine has
paper in it now. Serving one at the upload gate would accept an order the
printer cannot fulfil — precisely the case the brief forbids. Verified as tests
`T4.1` (a printer reporting `media-empty` is reported unavailable) and `T4.2`
(the upload is refused, HTTP 409, with nothing stored).

When a printer is unavailable the landing page shows exactly:

> Printing Service Currently Unavailable. Please try again later.

with no upload control present at all — verified as test `T4.3`.
