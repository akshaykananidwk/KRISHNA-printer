<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated symmetric encryption for secrets at rest (GitHub tokens,
 * payment gateway keys, agent shared secrets).
 *
 * Uses libsodium's XChaCha20-Poly1305 when available and falls back to
 * AES-256-GCM via OpenSSL. Both are AEAD constructions, so ciphertext is
 * tamper-evident; there is no unauthenticated path.
 *
 * Passwords are NEVER handled here — those use password_hash() one-way.
 */
final class Encrypter
{
    private const PREFIX_SODIUM = 'sod1:';
    private const PREFIX_OPENSSL = 'aes1:';

    private static ?string $key = null;

    /** Set the 32-byte key (raw binary or base64 with "base64:" prefix). */
    public static function setKey(string $key): void
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new \RuntimeException('APP_KEY is not valid base64.');
            }
            $key = $decoded;
        }
        if (strlen($key) !== 32) {
            throw new \RuntimeException('APP_KEY must decode to exactly 32 bytes.');
        }
        self::$key = $key;
    }

    public static function key(): string
    {
        if (self::$key === null) {
            $configured = (string) Config::get('app.key', '');
            if ($configured === '') {
                throw new \RuntimeException('Application encryption key is not configured.');
            }
            self::setKey($configured);
        }
        /** @var string */
        return self::$key;
    }

    public static function hasKey(): bool
    {
        return self::$key !== null || (string) Config::get('app.key', '') !== '';
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $nonce, $nonce, $key);
            return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();

        if (str_starts_with($payload, self::PREFIX_SODIUM)) {
            $raw = base64_decode(substr($payload, strlen(self::PREFIX_SODIUM)), true);
            if ($raw === false) {
                throw new \RuntimeException('Malformed ciphertext.');
            }
            $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($raw, 0, $nonceLen);
            $cipher = substr($raw, $nonceLen);
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $nonce, $nonce, $key);
            if ($plain === false) {
                throw new \RuntimeException('Ciphertext failed authentication.');
            }
            return $plain;
        }

        if (str_starts_with($payload, self::PREFIX_OPENSSL)) {
            $raw = base64_decode(substr($payload, strlen(self::PREFIX_OPENSSL)), true);
            if ($raw === false || strlen($raw) < 28) {
                throw new \RuntimeException('Malformed ciphertext.');
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new \RuntimeException('Ciphertext failed authentication.');
            }
            return $plain;
        }

        throw new \RuntimeException('Unrecognised ciphertext format.');
    }

    /** Mask a secret for display: keeps a short prefix/suffix only. */
    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . str_repeat('•', min(16, $len - 8)) . substr($secret, -4);
    }
}
