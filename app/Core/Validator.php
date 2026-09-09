<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;
use App\Support\PageRange;

/**
 * Whitelist input validator. Rules are declarative:
 *
 *   Validator::make($data, ['email' => 'required|email|max:190'])->validated()
 *
 * validated() returns ONLY the keys that had rules — unlisted input can never
 * leak into a model or query.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|array<int,string>> $rules
     * @param array<string,string> $labels
     */
    private function __construct(
        private array $data,
        private array $rules,
        private array $labels = []
    ) {
        $this->run();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|array<int,string>> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $required = in_array('required', $rules, true);
            $nullable = in_array('nullable', $rules, true);
            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($required && $isEmpty) {
                $this->errors[$field] = $this->label($field) . ' is required.';
                continue;
            }
            if ($isEmpty) {
                if ($nullable || !$required) {
                    // Preserve an explicitly-submitted empty value as null.
                    if (array_key_exists($field, $this->data)) {
                        $this->validated[$field] = $nullable ? null : $value;
                    }
                }
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->applyRule((string) $name, $parameter, $field, $value);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    continue 2;
                }
            }

            $this->validated[$field] = $this->cast($rules, $value);
        }
    }

    private function applyRule(string $name, ?string $parameter, string $field, mixed $value): ?string
    {
        $label = $this->label($field);

        return match ($name) {
            'string' => is_scalar($value) ? null : "$label must be text.",
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null : "$label must be a whole number.",
            'numeric' => is_numeric($value) ? null : "$label must be a number.",
            'decimal' => preg_match('/^\d{1,10}(\.\d{1,4})?$/', (string) $value) === 1
                ? null : "$label must be a valid amount.",
            'bool', 'boolean' => in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)
                ? null : "$label must be true or false.",
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false && strlen((string) $value) <= 190
                ? null : "$label must be a valid email address.",
            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) !== false
                ? null : "$label must be a valid URL.",
            'ip' => filter_var((string) $value, FILTER_VALIDATE_IP) !== false
                ? null : "$label must be a valid IP address.",
            'host' => $this->validHost((string) $value) ? null : "$label must be a valid hostname or IP address.",
            'port' => ((int) $value >= 1 && (int) $value <= 65535) ? null : "$label must be a port between 1 and 65535.",
            'min' => $this->compareMin($value, (float) $parameter)
                ? null : (is_string($value) && !is_numeric($value)
                    ? "$label must be at least $parameter characters."
                    : "$label must be at least $parameter."),
            'max' => $this->compareMax($value, (float) $parameter)
                ? null : (is_string($value) && !is_numeric($value)
                    ? "$label may not be longer than $parameter characters."
                    : "$label may not be greater than $parameter."),
            'between' => $this->between($value, (string) $parameter) ? null : "$label is out of range.",
            'in' => in_array((string) $value, explode(',', (string) $parameter), true)
                ? null : "$label has an unsupported value.",
            'regex' => preg_match((string) $parameter, (string) $value) === 1
                ? null : "$label has an invalid format.",
            'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) === 1
                ? null : "$label may contain lowercase letters, numbers and hyphens only.",
            'alpha_dash' => preg_match('/^[A-Za-z0-9_-]+$/', (string) $value) === 1
                ? null : "$label may contain letters, numbers, dashes and underscores only.",
            'date' => strtotime((string) $value) !== false ? null : "$label must be a valid date.",
            'array' => is_array($value) ? null : "$label must be a list.",
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value
                ? null : "$label confirmation does not match.",
            'page_range' => PageRange::isValid((string) $value) ? null : "$label must look like 1-5 or 2,4,8-10.",
            'json' => json_validate((string) $value) ? null : "$label must be valid JSON.",
            default => null,
        };
    }

    private function validHost(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return preg_match('/^(?=.{1,253}$)([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $value) === 1;
    }

    private function compareMin(mixed $value, float $min): bool
    {
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return is_numeric($value) ? (float) $value >= $min : mb_strlen((string) $value) >= $min;
    }

    private function compareMax(mixed $value, float $max): bool
    {
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return is_numeric($value) ? (float) $value <= $max : mb_strlen((string) $value) <= $max;
    }

    private function between(mixed $value, string $parameter): bool
    {
        [$min, $max] = array_pad(explode(',', $parameter, 2), 2, '0');
        return $this->compareMin($value, (float) $min) && $this->compareMax($value, (float) $max);
    }

    /** @param array<int,string> $rules */
    private function cast(array $rules, mixed $value): mixed
    {
        foreach ($rules as $rule) {
            $name = explode(':', $rule, 2)[0];
            if ($name === 'int' || $name === 'integer') {
                return (int) $value;
            }
            if ($name === 'numeric' || $name === 'decimal') {
                return (float) $value;
            }
            if ($name === 'bool' || $name === 'boolean') {
                return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
            }
        }
        return $value;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Add an error discovered outside the rule set (e.g. a uniqueness check). */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
        return $this->validated;
    }

    /** @return array<string,mixed> */
    public function safe(): array
    {
        return $this->validated;
    }
}
