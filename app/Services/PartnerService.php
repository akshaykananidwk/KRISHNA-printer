<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Models\Partner;
use App\Repositories\ApiTokenRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\LocationRepository;
use App\Repositories\PartnerRepository;

/**
 * Shop self-registration, from the public form to a working agent token.
 *
 * The shape of this, and the reason it is not simpler:
 *
 *   register()  writes a row and nothing else. A stranger filling in a public
 *               form must not be able to create a location, a printer or a
 *               token — that is the estate, and it belongs to the operator.
 *   approve()   is the only thing that creates them, and only an administrator
 *               can call it.
 *   issueToken() is the partner's own, because the alternative is an operator
 *               reading a token out to someone over the phone. The plaintext
 *               is returned here and then exists nowhere: only its hash is
 *               stored, exactly as for a token issued from the admin panel.
 *
 * There is no mail sender in this application, so nothing here emails anybody.
 * A partner learns they are approved by signing in and seeing it, which is
 * stated on the page they land on after registering rather than left for them
 * to discover.
 */
final class PartnerService
{
    public function __construct(
        private Database $db,
        private PartnerRepository $partners,
        private LocationRepository $locations,
        private DeviceRepository $devices,
        private ApiTokenRepository $tokens,
        private AuthService $auth,
        private QRCodeService $qr,
        private AuditService $audit
    ) {
    }

    /**
     * Record a registration. Creates nothing else.
     *
     * @param array<string,mixed> $data
     */
    public function register(array $data, string $ip): int
    {
        $id = $this->partners->create([
            'shop_name' => (string) $data['shop_name'],
            'contact_name' => (string) $data['contact_name'],
            'email' => mb_strtolower(trim((string) $data['email'])),
            'phone' => (string) $data['phone'],
            'password_hash' => $this->auth->hash((string) $data['password']),
            'address_line1' => $data['address_line1'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'status' => 'pending',
            'registered_ip' => $ip,
        ]);

        $this->audit->log(
            'partner.registered',
            sprintf('Shop "%s" registered and is waiting for approval.', $data['shop_name']),
            'partner',
            (string) $id,
            'notice'
        );

        return $id;
    }

    /**
     * Sign a partner in.
     *
     * @return array{success:bool,error:string,partner:?Partner}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $partner = $this->partners->findByEmail($email);

        // Same message and the same work either way: whether an email is
        // registered is not something a public form should tell anybody.
        $failure = ['success' => false, 'error' => 'That email and password do not match.', 'partner' => null];

        if ($partner === null) {
            $this->auth->hash($password);       // keep the timing even
            return $failure;
        }

        if ($partner->isLocked()) {
            return [
                'success' => false,
                'error' => 'Too many attempts. Try again in a few minutes.',
                'partner' => null,
            ];
        }

        if (!password_verify($password, $partner->string('password_hash'))) {
            $this->partners->recordFailedLogin(
                $partner->id(),
                (int) Config::get('security.login.max_attempts', 5),
                (int) Config::get('security.login.lockout_seconds', 900)
            );
            return $failure;
        }

        if ($partner->status() === 'rejected') {
            return [
                'success' => false,
                'error' => 'This registration was not approved. Please contact us.',
                'partner' => null,
            ];
        }

        $this->partners->recordSuccessfulLogin($partner->id(), $ip);
        Session::regenerate();
        Session::put('partner_id', $partner->id());

        return ['success' => true, 'error' => '', 'partner' => $partner];
    }

    public function current(): ?Partner
    {
        $id = Session::get('partner_id');
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        return $this->partners->find((int) $id);
    }

    public function logout(): void
    {
        Session::forget('partner_id');
        Session::regenerate();
    }

    /**
     * Approve a registration: create the shop's location and its print agent.
     *
     * One transaction, because a location without its agent is a shop that
     * looks live and cannot print, and an agent without its location is a row
     * pointing at nothing.
     *
     * @return array{location_id:int,device_id:int,code:string}
     */
    public function approve(Partner $partner, ?int $adminId): array
    {
        if ($partner->isApproved()) {
            return [
                'location_id' => (int) $partner->locationId(),
                'device_id' => (int) $partner->deviceId(),
                'code' => '',
            ];
        }

        $code = $this->uniqueLocationCode($partner->string('shop_name'));
        $qrToken = $this->qr->generateToken();

        $result = $this->db->transaction(function () use ($partner, $code, $qrToken): array {
            $locationId = $this->locations->create([
                'code' => $code,
                'name' => $partner->string('shop_name'),
                'address_line1' => $partner->string('address_line1'),
                'city' => $partner->string('city'),
                'state' => $partner->string('state'),
                'postal_code' => $partner->string('postal_code'),
                'contact_name' => $partner->string('contact_name'),
                'contact_phone' => $partner->string('phone'),
                'contact_email' => $partner->string('email'),
                'status' => 'active',
                'qr_token_hash' => $this->qr->hashToken($qrToken),
                'qr_token_hint' => substr($qrToken, 0, 8),
            ]);

            $deviceId = $this->devices->create([
                'location_id' => $locationId,
                'name' => $partner->string('shop_name') . ' counter',
                'device_uid' => $this->uuid(),
                'poll_interval_secs' => 10,
                'status' => 'pending',
            ]);

            return ['location_id' => $locationId, 'device_id' => $deviceId];
        });

        $this->partners->markReviewed(
            $partner->id(),
            'approved',
            $adminId,
            '',
            $result['location_id'],
            $result['device_id']
        );

        $this->audit->log(
            'partner.approved',
            sprintf(
                'Approved "%s"; created location %s and its print agent.',
                $partner->string('shop_name'),
                $code
            ),
            'partner',
            (string) $partner->id(),
            'notice'
        );

        // The QR link's plaintext exists only here. Held for one page view so
        // the operator can print the sticker, exactly as when a location is
        // created by hand.
        Session::put('new_qr_token_' . $result['location_id'], $qrToken);

        return $result + ['code' => $code];
    }

    public function reject(Partner $partner, ?int $adminId, string $reason): void
    {
        $this->partners->markReviewed($partner->id(), 'rejected', $adminId, $reason);

        $this->audit->log(
            'partner.rejected',
            sprintf('Declined "%s": %s', $partner->string('shop_name'), $reason),
            'partner',
            (string) $partner->id(),
            'warning'
        );
    }

    public function suspend(Partner $partner, ?int $adminId, string $reason): void
    {
        $this->partners->markReviewed($partner->id(), 'suspended', $adminId, $reason);

        $this->audit->log(
            'partner.suspended',
            sprintf('Suspended "%s": %s', $partner->string('shop_name'), $reason),
            'partner',
            (string) $partner->id(),
            'warning'
        );
    }

    /**
     * Issue a fresh agent token for this partner's own device.
     *
     * Any token already issued to that device is revoked first. A shop that
     * clicks this because it lost the old one has, by definition, a token it
     * cannot account for; leaving it working would be leaving a key under a
     * mat nobody can find.
     *
     * @return array{token:string,prefix:string}
     */
    public function issueToken(Partner $partner): array
    {
        $deviceId = $partner->deviceId();
        if (!$partner->isApproved() || $deviceId === null) {
            throw new \RuntimeException('This shop has no print agent yet.');
        }

        $this->tokens->revokeForDevice($deviceId);

        $issued = $this->tokens->issue(
            'Agent: ' . $partner->string('shop_name'),
            'agent',
            $deviceId,
            $partner->locationId(),
            null
        );

        $this->audit->log(
            'partner.token_issued',
            sprintf('"%s" issued a new agent token for their own shop.', $partner->string('shop_name')),
            'partner',
            (string) $partner->id(),
            'notice'
        );

        return ['token' => $issued['token'], 'prefix' => $issued['prefix']];
    }

    /**
     * A URL-safe code derived from the shop name, unique across the estate.
     *
     * The code appears in the QR link, so it is built from the name rather
     * than being a number: a sticker that reads "/print/ramesh-xerox/..." is
     * one an operator can match to a shop at a glance.
     */
    private function uniqueLocationCode(string $shopName): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $shopName), '-'));
        $base = substr($base, 0, 28);
        if ($base === '' || $base === '-') {
            $base = 'shop';
        }

        $code = $base;
        $suffix = 2;
        while ($this->locations->codeExists($code)) {
            $code = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 200) {
                $code = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $code;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
