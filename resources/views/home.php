<?php
/**
 * The front page.
 *
 * Whoever types the domain in gets this: a shop owner deciding whether to
 * join, an office manager wondering what the printer in the corner is doing,
 * or the operator on their way to the panel. It used to be the admin sign-in
 * box, which told all three of them nothing and offered two of them an
 * account they do not have.
 *
 * Everything claimed here is something the application does. Where a thing is
 * not built — separating each shop's payout at the gateway, for one — it says
 * so rather than implying it.
 *
 * @var App\Core\View $view
 * @var string $appName
 * @var bool $paymentsEnabled
 */

use App\Core\View;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($appName) ?> — cloud printing for shops, offices and campuses</title>
<meta name="description" content="Customers scan a QR code, upload from their phone and pay; the printer prints. Run 50 shops and 50 printers from one panel, with per-shop prices and per-printer page counts.">
<link rel="stylesheet" href="/assets/css/admin.css">
<style nonce="<?= View::e($cspNonce) ?>">
  .h-body { background: var(--canvas); }
  .h-wrap { max-width: 60rem; margin: 0 auto; padding: 0 1rem; }

  .h-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem;
           padding: 1rem 0; flex-wrap: wrap; }
  .h-logo { display: flex; align-items: center; gap: .5rem; font-weight: 700; font-size: 1.05rem; }
  .h-top__links { display: flex; gap: .5rem; flex-wrap: wrap; }

  .h-hero { padding: 2.5rem 0 2rem; }
  .h-hero h1 { font-size: clamp(1.7rem, 5vw, 2.6rem); line-height: 1.15; margin: 0 0 .8rem; }
  .h-hero p { font-size: 1.05rem; color: var(--ink-soft); max-width: 38rem; margin: 0 0 1.4rem; }
  .h-cta { display: flex; gap: .6rem; flex-wrap: wrap; }

  .h-section { padding: 2rem 0; border-top: 1px solid var(--line); }
  .h-section > h2 { font-size: 1.35rem; margin: 0 0 .4rem; }
  .h-section > .h-lede { color: var(--ink-soft); margin: 0 0 1.4rem; max-width: 40rem; }

  .h-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }
  .h-tile { background: var(--surface); border-radius: var(--radius); padding: 1.1rem 1.2rem;
            box-shadow: var(--shadow); }
  .h-tile h3 { margin: 0 0 .35rem; font-size: 1rem; }
  .h-tile p { margin: 0; color: var(--ink-soft); font-size: .92rem; line-height: 1.55; }

  .h-steps { counter-reset: step; list-style: none; padding: 0; margin: 0;
             display: grid; gap: .9rem; }
  .h-steps li { background: var(--surface); border-radius: var(--radius); padding: 1rem 1.1rem 1rem 3.2rem;
                position: relative; box-shadow: var(--shadow); }
  .h-steps li::before { counter-increment: step; content: counter(step);
                        position: absolute; left: 1rem; top: 1rem;
                        width: 1.6rem; height: 1.6rem; border-radius: 50%;
                        background: var(--brand); color: #fff; display: grid; place-items: center;
                        font-size: .85rem; font-weight: 700; }
  .h-steps strong { display: block; margin-bottom: .15rem; }
  .h-steps span { color: var(--ink-soft); font-size: .92rem; line-height: 1.55; }

  .h-two { display: grid; gap: 1rem; grid-template-columns: 1fr 1fr; }
  @media (max-width: 40rem) { .h-two { grid-template-columns: 1fr; } }

  .h-list { margin: .4rem 0 0; padding-left: 1.1rem; color: var(--ink-soft);
            font-size: .92rem; line-height: 1.7; }

  .h-note { background: var(--info-bg); border-radius: var(--radius); padding: 1rem 1.1rem;
            font-size: .92rem; line-height: 1.6; }

  .h-foot { padding: 2rem 0 3rem; border-top: 1px solid var(--line); color: var(--ink-faint);
            font-size: .88rem; display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
</style>
</head>
<body class="h-body">

<div class="h-wrap">
  <header class="h-top">
    <span class="h-logo"><span aria-hidden="true">🖨️</span> <?= View::e($appName) ?></span>
    <nav class="h-top__links">
      <a class="a-btn" href="/partner/login">Shop sign in</a>
      <a class="a-btn" href="/admin/login">Admin</a>
      <a class="a-btn a-btn--primary" href="/register">Register your shop</a>
    </nav>
  </header>

  <section class="h-hero">
    <h1>Your printer, run properly — from one panel.</h1>
    <p>
      A customer scans the QR code stuck on the printer, uploads from their phone, chooses what
      they want, pays<?= $paymentsEnabled ? '' : ' if you charge' ?>, and collects the print.
      You watch every shop, every printer and every rupee from one screen — whether that is one
      machine behind your counter or fifty across the city.
    </p>
    <div class="h-cta">
      <a class="a-btn a-btn--primary" href="/register">Register your shop</a>
      <a class="a-btn" href="#how">See how it works</a>
    </div>
  </section>

  <section class="h-section" id="how">
    <h2>How it works</h2>
    <p class="h-lede">
      No app for the customer to install, no account to make, and nothing opened on your network.
    </p>
    <ol class="h-steps">
      <li><strong>Scan</strong><span>The customer points their camera at the QR sticker on the printer. The page opens in their browser.</span></li>
      <li><strong>Check</strong><span>Before anything else, the page asks that printer whether it is actually ready. Out of paper, jammed, offline — it says so and offers no upload at all, so nobody pays for a print that was never going to come out.</span></li>
      <li><strong>Upload</strong><span>PDF, Word, Excel, PowerPoint, JPG or PNG. One file or several in one order.</span></li>
      <li><strong>Choose</strong><span>Copies, colour or black &amp; white, paper size, one side or both, which pages. Only the options that printer has actually been verified to support are offered.</span></li>
      <li><strong>Pay</strong><span>The price is broken down before they commit: pages, copies, mode, size, tax, total. Payment is verified on our side, never on the browser's word.</span></li>
      <li><strong>Print</strong><span>The job reaches the printer through the small agent running at the shop, and the customer gets a job number to collect against.</span></li>
    </ol>
  </section>

  <section class="h-section">
    <h2>Who it is for</h2>
    <p class="h-lede">It suits anywhere a printer is shared by people who are not its owner.</p>
    <div class="h-grid">
      <div class="h-tile">
        <h3>Xerox and stationery shops</h3>
        <p>Stop standing over a counter PC taking files on WhatsApp and pen drives. The queue runs
           itself, the price is charged before the paper moves, and you can see the day's takings
           without adding anything up.</p>
      </div>
      <div class="h-tile">
        <h3>Printers placed in offices</h3>
        <p>Put a machine in someone else's office on rent and you still know exactly what it did:
           pages printed, colour against black &amp; white, by day, by month, exported to a
           spreadsheet. Billing an office for what it actually used stops being an argument.</p>
      </div>
      <div class="h-tile">
        <h3>Colleges, hostels and libraries</h3>
        <p>Students print from their own phones without anybody's laptop, a pen drive, or a queue
           at a desk. Each printer takes work only when it is genuinely ready.</p>
      </div>
      <div class="h-tile">
        <h3>Cyber cafés and service centres</h3>
        <p>Several printers, several rates, one screen. Every job carries who ordered what, when,
           at which machine, and whether it printed.</p>
      </div>
    </div>
  </section>

  <section class="h-section">
    <h2>Each shop keeps its own rates</h2>
    <div class="h-two">
      <div>
        <p class="h-lede" style="margin-bottom:.6rem">
          Prices are set per shop, and per printer inside a shop if you need that. A4 black &amp;
          white at one rate here, colour at another there, a different rate again on the machine in
          the college — all at once, all from the panel.
        </p>
        <ul class="h-list">
          <li>Rates by paper size, colour mode and one side or both.</li>
          <li>Tax and any gateway fee shown to the customer, not buried.</li>
          <li>Reports split by shop and by printer: what each one earned, over any range you pick.</li>
          <li>Export to CSV, Excel or PDF.</li>
        </ul>
      </div>
      <div class="h-note">
        <strong>One thing to be clear about.</strong>
        Money from every shop is collected into the one payment account this system is configured
        with, and the reports tell you precisely what each shop earned so you can settle up. Paying
        each shop separately at the gateway itself is not part of this yet — if you need that, it
        is a change to make deliberately rather than something to assume.
      </div>
    </div>
  </section>

  <section class="h-section">
    <h2>Nothing is offered until it is proven</h2>
    <p class="h-lede">
      The most expensive thing a printing system can do is take money for something the printer
      cannot do. So a capability is only offered to a customer once it has been established.
    </p>
    <div class="h-grid">
      <div class="h-tile">
        <h3>From the datasheet</h3>
        <p>Recorded for your reference and <strong>never offered to a customer</strong>. A brochure
           saying a model supports something is not the same as this machine doing it.</p>
      </div>
      <div class="h-tile">
        <h3>From the printer</h3>
        <p>The printer itself reported it when asked. Offered.</p>
      </div>
      <div class="h-tile">
        <h3>From a test print</h3>
        <p>You pressed the button and paper came out right. Offered.</p>
      </div>
    </div>
    <p class="h-lede" style="margin-top:1rem">
      A printer nobody has checked offers nothing and takes no jobs. An unavailable printer is a
      much smaller problem than a customer paying for A3 on a machine that stops at A4.
    </p>
  </section>

  <section class="h-section">
    <h2>Your printer is never put on the internet</h2>
    <div class="h-two">
      <div>
        <p class="h-lede" style="margin-bottom:.6rem">
          You do not forward a port, open a firewall, or set up a VPN. A small program on the
          computer beside your printer dials <em>out</em> to us, collects only that shop's jobs,
          and prints them on your own network.
        </p>
        <ul class="h-list">
          <li>Windows 10 or 11 on the counter PC, or a Raspberry Pi — whichever you already have.</li>
          <li>Install it, paste your token, pick your printer. It then runs beside the clock.</li>
          <li>USB printers work. The printer needs no network of its own.</li>
        </ul>
      </div>
      <div class="h-note">
        <strong>Why it matters.</strong>
        Port 9100 — the port a network printer listens on — asks for no password at all. Anyone
        who can reach it can print to it, read what is queued, or empty your toner overnight.
        Exposing it to the internet is not a setting; it is a hole. This is why the connection only
        ever goes outwards.
      </div>
    </div>
  </section>

  <section class="h-section">
    <h2>What it does not do</h2>
    <p class="h-lede">Said here so you find out now rather than after you have committed.</p>
    <ul class="h-list">
      <li>It does not pay each shop separately at the payment gateway — see above.</li>
      <li>It does not scan, copy or fax. It prints.</li>
      <li>It cannot make a printer do what it cannot do. No colour on a mono machine, no A3 on an
          A4 one — it will refuse the order rather than take the money.</li>
      <li>It needs one always-on computer at each shop. Without it, that shop's printer is not
          reachable and the page says so.</li>
    </ul>
  </section>

  <section class="h-section">
    <h2>Start</h2>
    <div class="h-cta">
      <a class="a-btn a-btn--primary" href="/register">Register your shop</a>
      <a class="a-btn" href="/partner/login">Already registered — sign in</a>
    </div>
    <p class="h-lede" style="margin-top:1rem">
      Registering takes a minute. We check the details, switch your shop on, and you get the token
      to paste into the software beside your printer.
    </p>
  </section>

  <footer class="h-foot">
    <span><?= View::e($appName) ?></span>
    <span><a href="/admin/login">Management panel</a></span>
  </footer>
</div>

</body>
</html>
