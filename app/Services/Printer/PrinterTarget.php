<?php
declare(strict_types=1);

namespace App\Services\Printer;

use App\Core\Encrypter;

/**
 * Immutable connection descriptor passed to a driver. Built from a
 * printer_connections row; decrypts the stored password only on demand so the
 * plaintext exists for the shortest possible time.
 */
final class PrinterTarget
{
    /** @param array<string,mixed> $options */
    public function __construct(
        public readonly int $printerId,
        public readonly string $driver,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly bool $useTls = false,
        public readonly bool $verifyTls = true,
        public readonly ?string $ippPath = null,
        public readonly ?string $lpdQueue = null,
        public readonly ?string $username = null,
        private readonly ?string $passwordEncrypted = null,
        public readonly int $timeoutSeconds = 10,
        public readonly array $options = [],
        public readonly ?int $deviceId = null,
        public readonly ?string $queueName = null,
        public readonly string $capabilityProfile = 'generic',
        public readonly ?string $model = null,
        public readonly ?string $printerName = null,
    ) {
    }

    /** @param array<string,mixed> $connection printer_connections row */
    public static function fromConnection(array $connection, array $printer = []): self
    {
        $options = [];
        if (is_string($connection['options_json'] ?? null) && $connection['options_json'] !== '') {
            $decoded = json_decode((string) $connection['options_json'], true);
            $options = is_array($decoded) ? $decoded : [];
        }

        return new self(
            printerId: (int) ($connection['printer_id'] ?? ($printer['id'] ?? 0)),
            driver: (string) ($connection['driver'] ?? 'agent'),
            host: $connection['host'] === null ? null : (string) $connection['host'],
            port: $connection['port'] === null ? null : (int) $connection['port'],
            useTls: (bool) (int) ($connection['use_tls'] ?? 0),
            verifyTls: (bool) (int) ($connection['verify_tls'] ?? 1),
            ippPath: $connection['ipp_path'] === null ? null : (string) $connection['ipp_path'],
            lpdQueue: $connection['lpd_queue'] === null ? null : (string) $connection['lpd_queue'],
            username: $connection['username'] === null ? null : (string) $connection['username'],
            passwordEncrypted: $connection['password_encrypted'] === null ? null : (string) $connection['password_encrypted'],
            timeoutSeconds: (int) ($connection['timeout_seconds'] ?? 10),
            options: $options,
            deviceId: isset($printer['device_id']) && $printer['device_id'] !== null ? (int) $printer['device_id'] : null,
            queueName: isset($printer['queue_name']) && $printer['queue_name'] !== null ? (string) $printer['queue_name'] : null,
            capabilityProfile: (string) ($printer['capability_profile'] ?? 'generic'),
            model: isset($printer['model']) ? (string) $printer['model'] : null,
            printerName: isset($printer['name']) ? (string) $printer['name'] : null,
        );
    }

    public function password(): ?string
    {
        if ($this->passwordEncrypted === null || $this->passwordEncrypted === '') {
            return null;
        }
        try {
            return Encrypter::decrypt($this->passwordEncrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasCredentials(): bool
    {
        return $this->username !== null && $this->username !== '' && $this->passwordEncrypted !== null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** Full IPP URI for this target. */
    public function ippUri(): string
    {
        $scheme = $this->useTls ? 'ipps' : 'ipp';
        $port = $this->port ?? ($this->useTls ? 443 : 631);
        $path = $this->ippPath ?? '/ipp/print';
        return sprintf('%s://%s:%d%s', $scheme, (string) $this->host, $port, '/' . ltrim($path, '/'));
    }

    /** The HTTP(S) URL an IPP request is actually POSTed to. */
    public function ippHttpUrl(): string
    {
        $scheme = $this->useTls ? 'https' : 'http';
        $port = $this->port ?? ($this->useTls ? 443 : 631);
        $path = $this->ippPath ?? '/ipp/print';
        return sprintf('%s://%s:%d%s', $scheme, (string) $this->host, $port, '/' . ltrim($path, '/'));
    }

    public function describe(): string
    {
        return match ($this->driver) {
            'agent' => 'Location agent' . ($this->queueName !== null ? ' → ' . $this->queueName : ''),
            'ipp' => $this->ippUri(),
            'raw9100' => sprintf('raw://%s:%d', (string) $this->host, $this->port ?? 9100),
            'lpd' => sprintf('lpd://%s:%d/%s', (string) $this->host, $this->port ?? 515, (string) $this->lpdQueue),
            default => $this->driver,
        };
    }
}
