<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Location;
use App\Repositories\LocationRepository;
use App\Support\QrEncoder;

/**
 * QR code generation and token lifecycle for locations.
 *
 * SECURITY MODEL
 * --------------
 * The QR code encodes only a public location code and a random 128-bit token:
 *
 *     https://host/print/{location-code}/{token}
 *
 * It carries NO printer address, NO credentials and NO internal identifiers.
 * Anyone photographing the sticker learns the URL and nothing else — the same
 * thing they learn by scanning it, which is the point.
 *
 * The token is stored only as a SHA-256 hash, so a stolen database dump does
 * not yield working QR links. The plaintext exists in exactly two places: the
 * printed sticker, and the one-time download an operator takes when creating
 * the location.
 *
 * Rotating a token invalidates every printed sticker for that location, which
 * is exactly what you want when one goes missing.
 */
final class QRCodeService
{
    public function __construct(
        private LocationRepository $locations,
        private AuditService $audit
    ) {
    }

    /** Generate a fresh token. Returns the plaintext; only the hash is stored. */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a new token for a location, invalidating any previous one.
     *
     * @return string the plaintext token — show it once, then it is gone
     */
    public function rotateToken(int $locationId, ?int $adminId = null): string
    {
        $token = $this->generateToken();

        $this->locations->updateById($locationId, [
            'qr_token_hash' => $this->hashToken($token),
            'qr_token_hint' => substr($token, 0, 8),
            'qr_token_issued_at' => gmdate('Y-m-d H:i:s'),
            'qr_token_rotated_by' => $adminId,
        ]);

        $this->audit->log(
            'location.qr_rotated',
            sprintf(
                'Rotated the QR token for location #%d. Every previously printed QR code for this '
                . 'location has stopped working and must be replaced.',
                $locationId
            ),
            'location',
            (string) $locationId,
            'notice'
        );

        return $token;
    }

    /** The customer-facing URL a QR code points at. */
    public function url(string $locationCode, string $token): string
    {
        $base = rtrim((string) Config::get('settings.app_url', Config::get('app.url', '')), '/');

        if ($base === '') {
            // Fall back to the current request's origin so a fresh install
            // still produces a working link before app_url is configured.
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }

        return sprintf('%s/print/%s/%s', $base, rawurlencode($locationCode), rawurlencode($token));
    }

    /**
     * Render a QR code.
     *
     * Error correction defaults to Q (≈25% recovery) rather than the usual M,
     * because these stickers live on counters and get scuffed, wet and partly
     * covered. The extra redundancy costs a slightly denser code and buys a lot
     * of real-world reliability.
     *
     * @return array{svg:string,png:?string,url:string,version:int,modules:int}
     */
    public function render(string $locationCode, string $token, int $moduleSize = 8, int $eccLevel = QrEncoder::ECC_Q): array
    {
        $url = $this->url($locationCode, $token);
        $encoder = new QrEncoder($url, $eccLevel);

        return [
            'svg' => $encoder->toSvg($moduleSize, 4),
            'png' => $encoder->toPng($moduleSize, 4),
            'url' => $url,
            'version' => $encoder->version(),
            'modules' => $encoder->size(),
        ];
    }

    public function svgFor(string $locationCode, string $token, int $moduleSize = 8): string
    {
        return (new QrEncoder($this->url($locationCode, $token), QrEncoder::ECC_Q))->toSvg($moduleSize, 4);
    }

    public function pngFor(string $locationCode, string $token, int $moduleSize = 10): ?string
    {
        return (new QrEncoder($this->url($locationCode, $token), QrEncoder::ECC_Q))->toPng($moduleSize, 4);
    }

    /**
     * A print-ready A4 poster for a location, as standalone HTML.
     *
     * Delivered as HTML rather than a generated PDF so it prints correctly from
     * any browser without a PDF toolchain on the server, and so an operator can
     * adjust it before printing.
     */
    public function printableSheet(Location $location, string $token): string
    {
        $render = $this->render($location->string('code'), $token, 10);
        $businessName = (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer'));

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Print QR — ' . $e($location->string('name')) . '</title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
    color: #14261f; background: #fff;
    display: flex; align-items: center; justify-content: center; min-height: 100vh;
  }
  .sheet { width: 186mm; text-align: center; padding: 10mm 8mm; }
  .brand { font-size: 15pt; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #1e6f5c; }
  h1 { font-size: 30pt; margin: 4mm 0 2mm; line-height: 1.15; }
  .address { font-size: 11pt; color: #5a6b64; margin-bottom: 7mm; }
  .headline { font-size: 20pt; font-weight: 700; margin: 0 0 5mm; }
  .qr { display: inline-block; padding: 6mm; border: 2.5mm solid #1e6f5c; border-radius: 4mm; background: #fff; }
  .qr svg { display: block; width: 88mm; height: 88mm; }
  .steps { display: flex; gap: 6mm; justify-content: center; margin: 8mm 0 5mm; }
  .step { flex: 1; max-width: 44mm; }
  .step .n {
    width: 11mm; height: 11mm; border-radius: 50%; background: #1e6f5c; color: #fff;
    font-size: 15pt; font-weight: 700; display: flex; align-items: center;
    justify-content: center; margin: 0 auto 2.5mm;
  }
  .step p { font-size: 10.5pt; margin: 0; line-height: 1.35; }
  .code { font-size: 9pt; color: #8a9691; margin-top: 5mm; font-family: ui-monospace, "SFMono-Regular", monospace; }
  .url { font-size: 9pt; color: #5a6b64; word-break: break-all; margin-top: 2mm; }
  @media print { body { min-height: auto; } .no-print { display: none; } }
  .no-print { margin-top: 8mm; }
  .no-print button {
    font: inherit; font-size: 11pt; padding: 3mm 8mm; border-radius: 2mm;
    border: 0; background: #1e6f5c; color: #fff; cursor: pointer;
  }
</style>
</head>
<body>
<div class="sheet">
  <div class="brand">' . $e($businessName) . '</div>
  <h1>' . $e($location->string('name')) . '</h1>
  <div class="address">' . $e($location->shortAddress()) . '</div>

  <p class="headline">Scan to print from your phone</p>

  <div class="qr">' . $render['svg'] . '</div>

  <div class="steps">
    <div class="step"><div class="n">1</div><p>Scan this code with your camera</p></div>
    <div class="step"><div class="n">2</div><p>Upload your file</p></div>
    <div class="step"><div class="n">3</div><p>Choose settings &amp; pay</p></div>
    <div class="step"><div class="n">4</div><p>Collect your printout</p></div>
  </div>

  <div class="code">Location code: ' . $e($location->string('code')) . '</div>
  <div class="url">' . $e($render['url']) . '</div>

  <div class="no-print"><button onclick="window.print()">Print this sheet</button></div>
</div>
</body>
</html>';
    }

    /**
     * A contact sheet of QR codes for many locations, for a rollout across
     * 50+ sites in one print run.
     *
     * @param array<int,array{location:Location,token:string}> $entries
     */
    public function batchSheet(array $entries): string
    {
        $businessName = (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer'));
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $cards = '';
        foreach ($entries as $entry) {
            $location = $entry['location'];
            $svg = $this->svgFor($location->string('code'), $entry['token'], 6);
            $cards .= '<div class="card">'
                . '<div class="qr">' . $svg . '</div>'
                . '<div class="name">' . $e($location->string('name')) . '</div>'
                . '<div class="meta">' . $e($location->shortAddress()) . '</div>'
                . '<div class="code">' . $e($location->string('code')) . '</div>'
                . '</div>';
        }

        return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>QR codes — ' . $e($businessName) . '</title>
<style>
  @page { size: A4; margin: 10mm; }
  body { margin: 0; font-family: "Segoe UI", system-ui, sans-serif; color: #14261f; }
  h1 { font-size: 14pt; margin: 0 0 5mm; }
  .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5mm; }
  .card {
    border: 0.4mm solid #cfdcd7; border-radius: 2mm; padding: 4mm;
    text-align: center; break-inside: avoid; page-break-inside: avoid;
  }
  .card .qr svg { width: 46mm; height: 46mm; display: block; margin: 0 auto; }
  .name { font-size: 10pt; font-weight: 700; margin-top: 2mm; }
  .meta { font-size: 8pt; color: #5a6b64; }
  .code { font-size: 7.5pt; color: #8a9691; font-family: ui-monospace, monospace; margin-top: 1mm; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<h1>' . $e($businessName) . ' — location QR codes (' . count($entries) . ')</h1>
<div class="grid">' . $cards . '</div>
<p class="no-print"><button onclick="window.print()">Print</button></p>
</body>
</html>';
    }
}
