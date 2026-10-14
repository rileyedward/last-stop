<?php

namespace App\Game\Engine;

/**
 * Typed reads from a decoded state array, so the state classes can be
 * rebuilt from cache without trusting loose types.
 */
final class Reader
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    public function int(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) ($this->data[$key] ?? $default);
    }

    public function nullableBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : (bool) $value;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return is_array($value) ? array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    /**
     * @return array<string, int>
     */
    public function intMap(string $key): array
    {
        $value = $this->data[$key] ?? [];
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $out[(string) $k] = is_numeric($v) ? (int) $v : 0;
            }
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    public function boolMap(string $key): array
    {
        $value = $this->data[$key] ?? [];
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $out[(string) $k] = (bool) $v;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->data[$key] ?? [];
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function maps(string $key): array
    {
        $value = $this->data[$key] ?? [];
        $out = [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $row = [];
                    foreach ($item as $k => $v) {
                        $row[(string) $k] = $v;
                    }
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    public function reader(string $key): self
    {
        $value = $this->data[$key] ?? [];

        return new self(is_array($value) ? $value : []);
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}
