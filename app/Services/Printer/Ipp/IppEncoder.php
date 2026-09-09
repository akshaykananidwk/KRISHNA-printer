<?php
declare(strict_types=1);

namespace App\Services\Printer\Ipp;

/**
 * Encoder for the IPP binary message format (RFC 8010).
 *
 * Layout of a request:
 *   version-number   2 bytes  (major, minor)
 *   operation-id     2 bytes
 *   request-id       4 bytes
 *   attribute-groups (repeating)
 *   end-of-attributes-tag (0x03)
 *   [document data]
 *
 * Each attribute inside a group is:
 *   value-tag 1 | name-length 2 | name | value-length 2 | value
 * and an additional value in a 1setOf repeats the tag with a zero-length name.
 */
final class IppEncoder
{
    // Delimiter tags
    public const TAG_OPERATION_ATTRIBUTES = 0x01;
    public const TAG_JOB_ATTRIBUTES = 0x02;
    public const TAG_END_OF_ATTRIBUTES = 0x03;
    public const TAG_PRINTER_ATTRIBUTES = 0x04;
    public const TAG_UNSUPPORTED_ATTRIBUTES = 0x05;

    // Out-of-band value tags
    public const TAG_UNSUPPORTED_VALUE = 0x10;
    public const TAG_UNKNOWN = 0x12;
    public const TAG_NO_VALUE = 0x13;

    // Integer value tags
    public const TAG_INTEGER = 0x21;
    public const TAG_BOOLEAN = 0x22;
    public const TAG_ENUM = 0x23;

    // octetString value tags
    public const TAG_OCTET_STRING = 0x30;
    public const TAG_DATE_TIME = 0x31;
    public const TAG_RESOLUTION = 0x32;
    public const TAG_RANGE_OF_INTEGER = 0x33;
    public const TAG_BEG_COLLECTION = 0x34;
    public const TAG_TEXT_WITH_LANGUAGE = 0x35;
    public const TAG_NAME_WITH_LANGUAGE = 0x36;
    public const TAG_END_COLLECTION = 0x37;

    // character-string value tags
    public const TAG_TEXT = 0x41;
    public const TAG_NAME = 0x42;
    public const TAG_KEYWORD = 0x44;
    public const TAG_URI = 0x45;
    public const TAG_URI_SCHEME = 0x46;
    public const TAG_CHARSET = 0x47;
    public const TAG_NATURAL_LANGUAGE = 0x48;
    public const TAG_MIME_MEDIA_TYPE = 0x49;
    public const TAG_MEMBER_ATTR_NAME = 0x4A;

    // Operations we use
    public const OP_PRINT_JOB = 0x0002;
    public const OP_VALIDATE_JOB = 0x0004;
    public const OP_CANCEL_JOB = 0x0008;
    public const OP_GET_JOB_ATTRIBUTES = 0x0009;
    public const OP_GET_JOBS = 0x000A;
    public const OP_GET_PRINTER_ATTRIBUTES = 0x000B;

    private string $buffer = '';

    public function __construct(
        private int $operation,
        private int $requestId,
        private int $versionMajor = 1,
        private int $versionMinor = 1
    ) {
        $this->buffer = pack('CCnN', $this->versionMajor, $this->versionMinor, $this->operation, $this->requestId);
    }

    public function beginGroup(int $tag): self
    {
        $this->buffer .= chr($tag);
        return $this;
    }

    /** A named attribute with a single value. */
    public function attribute(int $tag, string $name, string $value): self
    {
        $this->buffer .= chr($tag)
            . pack('n', strlen($name)) . $name
            . pack('n', strlen($value)) . $value;
        return $this;
    }

    /** An additional value for the attribute just written (1setOf). */
    public function additionalValue(int $tag, string $value): self
    {
        $this->buffer .= chr($tag)
            . pack('n', 0)
            . pack('n', strlen($value)) . $value;
        return $this;
    }

    public function integer(string $name, int $value): self
    {
        return $this->attribute(self::TAG_INTEGER, $name, pack('N', $value));
    }

    public function enum(string $name, int $value): self
    {
        return $this->attribute(self::TAG_ENUM, $name, pack('N', $value));
    }

    public function boolean(string $name, bool $value): self
    {
        return $this->attribute(self::TAG_BOOLEAN, $name, chr($value ? 1 : 0));
    }

    public function keyword(string $name, string $value): self
    {
        return $this->attribute(self::TAG_KEYWORD, $name, $value);
    }

    public function text(string $name, string $value): self
    {
        return $this->attribute(self::TAG_TEXT, $name, $value);
    }

    public function nameValue(string $name, string $value): self
    {
        return $this->attribute(self::TAG_NAME, $name, $value);
    }

    public function uri(string $name, string $value): self
    {
        return $this->attribute(self::TAG_URI, $name, $value);
    }

    public function charset(string $name, string $value): self
    {
        return $this->attribute(self::TAG_CHARSET, $name, $value);
    }

    public function naturalLanguage(string $name, string $value): self
    {
        return $this->attribute(self::TAG_NATURAL_LANGUAGE, $name, $value);
    }

    public function mimeMediaType(string $name, string $value): self
    {
        return $this->attribute(self::TAG_MIME_MEDIA_TYPE, $name, $value);
    }

    /** rangeOfInteger is two 4-byte signed integers: lower then upper. */
    public function rangeOfInteger(string $name, int $lower, int $upper): self
    {
        return $this->attribute(self::TAG_RANGE_OF_INTEGER, $name, pack('NN', $lower, $upper));
    }

    public function additionalRangeOfInteger(int $lower, int $upper): self
    {
        return $this->additionalValue(self::TAG_RANGE_OF_INTEGER, pack('NN', $lower, $upper));
    }

    /** @param array<int,string> $values */
    public function keywordSet(string $name, array $values): self
    {
        $first = true;
        foreach ($values as $value) {
            if ($first) {
                $this->keyword($name, $value);
                $first = false;
            } else {
                $this->additionalValue(self::TAG_KEYWORD, $value);
            }
        }
        return $this;
    }

    /**
     * The three operation attributes every IPP request must carry first, in
     * this order (RFC 8011 §4.1.4): attributes-charset, then
     * attributes-natural-language, then the target URI.
     */
    public function standardOperationAttributes(string $printerUri, ?string $requestingUser = null): self
    {
        $this->charset('attributes-charset', 'utf-8');
        $this->naturalLanguage('attributes-natural-language', 'en-us');
        $this->uri('printer-uri', $printerUri);
        if ($requestingUser !== null && $requestingUser !== '') {
            $this->nameValue('requesting-user-name', $requestingUser);
        }
        return $this;
    }

    public function end(): self
    {
        $this->buffer .= chr(self::TAG_END_OF_ATTRIBUTES);
        return $this;
    }

    public function toString(): string
    {
        return $this->buffer;
    }

    public function withDocument(string $document): string
    {
        return $this->buffer . $document;
    }
}
