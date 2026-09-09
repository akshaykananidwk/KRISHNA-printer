<?php
declare(strict_types=1);

namespace App\Services\Printer\Ipp;

/**
 * Decoder for IPP responses (RFC 8010).
 *
 * Parses the status code and every attribute group into a plain array. Values
 * that appear more than once for a name become a list, which is how 1setOf
 * attributes like media-supported arrive.
 */
final class IppDecoder
{
    private int $offset = 0;

    private int $statusCode = 0;

    private int $requestId = 0;

    /** @var array<string,array<string,mixed>> group name => attributes */
    private array $groups = [];

    /** @var array<string,mixed> flattened attribute name => value */
    private array $attributes = [];

    public function __construct(private string $data)
    {
        $this->parse();
    }

    private function parse(): void
    {
        if (strlen($this->data) < 8) {
            throw new IppException('IPP response is too short to contain a header.');
        }

        // version-number (2) + status-code (2) + request-id (4)
        $this->offset = 2;
        $this->statusCode = $this->readUint16();
        $this->requestId = $this->readUint32();

        $currentGroup = 'operation';
        $lastName = '';

        while ($this->offset < strlen($this->data)) {
            $tag = ord($this->data[$this->offset]);
            $this->offset++;

            if ($tag === IppEncoder::TAG_END_OF_ATTRIBUTES) {
                break;
            }

            // Delimiter tags (0x00-0x0F) start a new attribute group.
            if ($tag <= 0x0F) {
                $currentGroup = match ($tag) {
                    IppEncoder::TAG_OPERATION_ATTRIBUTES => 'operation',
                    IppEncoder::TAG_JOB_ATTRIBUTES => 'job',
                    IppEncoder::TAG_PRINTER_ATTRIBUTES => 'printer',
                    IppEncoder::TAG_UNSUPPORTED_ATTRIBUTES => 'unsupported',
                    default => 'group_' . $tag,
                };
                $lastName = '';
                continue;
            }

            $nameLength = $this->readUint16();
            $name = $nameLength > 0 ? $this->readBytes($nameLength) : '';
            $valueLength = $this->readUint16();
            $rawValue = $valueLength > 0 ? $this->readBytes($valueLength) : '';

            $value = $this->decodeValue($tag, $rawValue);

            if ($name === '') {
                // Additional value for the previous attribute (1setOf).
                $name = $lastName;
                if ($name === '') {
                    continue;
                }
                $existing = $this->groups[$currentGroup][$name] ?? null;
                if (is_array($existing) && array_is_list($existing)) {
                    $existing[] = $value;
                    $this->groups[$currentGroup][$name] = $existing;
                } else {
                    $this->groups[$currentGroup][$name] = [$existing, $value];
                }
            } else {
                $lastName = $name;
                $this->groups[$currentGroup][$name] = $value;
            }
        }

        foreach ($this->groups as $attributes) {
            foreach ($attributes as $name => $value) {
                // A later group wins only if the earlier value was empty; this
                // keeps printer attributes from being shadowed by operation ones.
                if (!array_key_exists($name, $this->attributes) || $this->attributes[$name] === null) {
                    $this->attributes[$name] = $value;
                }
            }
        }
    }

    private function decodeValue(int $tag, string $raw): mixed
    {
        return match ($tag) {
            IppEncoder::TAG_INTEGER, IppEncoder::TAG_ENUM => $this->decodeSignedInt($raw),
            IppEncoder::TAG_BOOLEAN => $raw !== '' && ord($raw[0]) === 1,
            IppEncoder::TAG_RANGE_OF_INTEGER => strlen($raw) >= 8
                ? ['lower' => $this->decodeSignedInt(substr($raw, 0, 4)), 'upper' => $this->decodeSignedInt(substr($raw, 4, 4))]
                : null,
            IppEncoder::TAG_RESOLUTION => strlen($raw) >= 9
                ? [
                    'cross_feed' => $this->decodeSignedInt(substr($raw, 0, 4)),
                    'feed' => $this->decodeSignedInt(substr($raw, 4, 4)),
                    'units' => ord($raw[8]) === 3 ? 'dpi' : 'dpcm',
                ]
                : null,
            IppEncoder::TAG_DATE_TIME => $this->decodeDateTime($raw),
            IppEncoder::TAG_TEXT_WITH_LANGUAGE, IppEncoder::TAG_NAME_WITH_LANGUAGE => $this->decodeWithLanguage($raw),
            IppEncoder::TAG_NO_VALUE, IppEncoder::TAG_UNKNOWN, IppEncoder::TAG_UNSUPPORTED_VALUE => null,
            // begCollection/endCollection: collections are returned as markers.
            // No attribute this application reads uses them, and silently
            // flattening them would misreport structure.
            IppEncoder::TAG_BEG_COLLECTION => '__collection_start__',
            IppEncoder::TAG_END_COLLECTION => '__collection_end__',
            default => $raw,
        };
    }

    /** IPP integers are 4-byte two's-complement big-endian and may be negative. */
    private function decodeSignedInt(string $raw): int
    {
        if (strlen($raw) < 4) {
            return 0;
        }
        $unsigned = unpack('N', substr($raw, 0, 4));
        $value = (int) ($unsigned[1] ?? 0);
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private function decodeDateTime(string $raw): ?string
    {
        if (strlen($raw) < 11) {
            return null;
        }
        $parts = unpack('nyear/Cmonth/Cday/Chour/Cminute/Csecond/Cdeci/Adirection/Choffh/Choffm', $raw);
        if ($parts === false) {
            return null;
        }
        return sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d',
            $parts['year'],
            $parts['month'],
            $parts['day'],
            $parts['hour'],
            $parts['minute'],
            $parts['second']
        );
    }

    /** textWithLanguage: language-length(2) language value-length(2) value */
    private function decodeWithLanguage(string $raw): string
    {
        if (strlen($raw) < 4) {
            return $raw;
        }
        $langLength = (int) (unpack('n', substr($raw, 0, 2))[1] ?? 0);
        $offset = 2 + $langLength;
        if (strlen($raw) < $offset + 2) {
            return $raw;
        }
        $valueLength = (int) (unpack('n', substr($raw, $offset, 2))[1] ?? 0);
        return substr($raw, $offset + 2, $valueLength);
    }

    private function readUint16(): int
    {
        $value = unpack('n', $this->readBytes(2));
        return (int) ($value[1] ?? 0);
    }

    private function readUint32(): int
    {
        $value = unpack('N', $this->readBytes(4));
        return (int) ($value[1] ?? 0);
    }

    private function readBytes(int $length): string
    {
        if ($this->offset + $length > strlen($this->data)) {
            throw new IppException('IPP response ended unexpectedly while reading ' . $length . ' bytes.');
        }
        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $bytes;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function requestId(): int
    {
        return $this->requestId;
    }

    /** IPP status codes below 0x0100 are the successful family. */
    public function isSuccess(): bool
    {
        return $this->statusCode < 0x0100;
    }

    public function statusMessage(): string
    {
        $known = [
            0x0000 => 'successful-ok',
            0x0001 => 'successful-ok-ignored-or-substituted-attributes',
            0x0002 => 'successful-ok-conflicting-attributes',
            0x0400 => 'client-error-bad-request',
            0x0401 => 'client-error-forbidden',
            0x0402 => 'client-error-not-authenticated',
            0x0403 => 'client-error-not-authorized',
            0x0404 => 'client-error-not-possible',
            0x0405 => 'client-error-timeout',
            0x0406 => 'client-error-not-found',
            0x0407 => 'client-error-gone',
            0x0408 => 'client-error-request-entity-too-large',
            0x0409 => 'client-error-request-value-too-long',
            0x040A => 'client-error-document-format-not-supported',
            0x040B => 'client-error-attributes-or-values-not-supported',
            0x040C => 'client-error-uri-scheme-not-supported',
            0x040D => 'client-error-charset-not-supported',
            0x040E => 'client-error-conflicting-attributes',
            0x040F => 'client-error-compression-not-supported',
            0x0410 => 'client-error-compression-error',
            0x0411 => 'client-error-document-format-error',
            0x0412 => 'client-error-document-access-error',
            0x0500 => 'server-error-internal-error',
            0x0501 => 'server-error-operation-not-supported',
            0x0502 => 'server-error-service-unavailable',
            0x0503 => 'server-error-version-not-supported',
            0x0504 => 'server-error-device-error',
            0x0505 => 'server-error-temporary-error',
            0x0506 => 'server-error-not-accepting-jobs',
            0x0507 => 'server-error-busy',
            0x0508 => 'server-error-job-canceled',
            0x0509 => 'server-error-multiple-document-jobs-not-supported',
        ];
        $message = (string) ($this->attributes['status-message'] ?? '');
        $label = $known[$this->statusCode] ?? sprintf('status-0x%04X', $this->statusCode);
        return $message !== '' ? "$label: $message" : $label;
    }

    /** True for status codes that will still fail on a retry. */
    public function isPermanentError(): bool
    {
        return in_array($this->statusCode, [
            0x0400, 0x0401, 0x0402, 0x0403, 0x0406, 0x0407,
            0x040A, 0x040B, 0x040C, 0x040D, 0x040F, 0x0411,
            0x0501, 0x0503, 0x0509,
        ], true);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /** Always returns a list, whether the attribute held one value or many. @return array<int,mixed> */
    public function getList(string $name): array
    {
        $value = $this->attributes[$name] ?? null;
        if ($value === null) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @return array<string,array<string,mixed>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<string,mixed> */
    public function group(string $name): array
    {
        return $this->groups[$name] ?? [];
    }
}
