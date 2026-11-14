<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class Toml
{
    private int $at = 0;

    private readonly int $length;

    private function __construct(private readonly string $text)
    {
        $this->length = strlen($text);
    }

    /**
     * @return array<string, string|list<string>>
     */
    public static function table(string $document, string $name): array
    {
        return (new self($document))->read(self::key($name));
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function read(string $name): array
    {
        $found = [];
        $table = '';

        while ($this->skipBlank() < $this->length) {
            if ($this->text[$this->at] === '[') {
                $table = $this->header();

                if ($table === null) {
                    return $found;
                }

                continue;
            }

            $key = $this->keyName();
            $value = $key === null ? null : $this->value(false);

            if ($key === null || $value === null) {
                return $found;
            }

            if ($table === $name) {
                $found[$key] = $value;
            }

            $this->skipLine();
        }

        return $found;
    }

    private function header(): ?string
    {
        $tables = substr($this->text, $this->at, 2) === '[[';
        $end = $this->until($tables ? ']]' : ']');

        if ($end === null) {
            return null;
        }

        $name = substr($this->text, $this->at + ($tables ? 2 : 1), $end - $this->at - ($tables ? 2 : 1));
        $this->at = $end + ($tables ? 2 : 1);
        $this->skipLine();

        return $tables ? "\0" : self::key($name);
    }

    private function keyName(): ?string
    {
        $end = $this->until('=');

        if ($end === null) {
            return null;
        }

        $key = self::key(substr($this->text, $this->at, $end - $this->at));
        $this->at = $end + 1;

        return $key === '' ? null : $key;
    }

    private function until(string $stop): ?int
    {
        $quote = null;

        for ($i = $this->at; $i < $this->length; $i++) {
            $char = $this->text[$i];

            if ($char === "\n") {
                return null;
            }

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (substr($this->text, $i, strlen($stop)) === $stop) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return string|list<string>|null
     */
    private function value(bool $inArray): string|array|null
    {
        while ($this->at < $this->length && ($this->text[$this->at] === ' ' || $this->text[$this->at] === "\t")) {
            $this->at++;
        }

        return match ($this->text[$this->at] ?? '') {
            '"', "'" => $this->string(),
            '[' => $this->array(),
            '{' => $this->inlineTable(),
            default => $this->bare($inArray),
        };
    }

    private function string(): ?string
    {
        $quote = $this->text[$this->at];

        if (substr($this->text, $this->at, 3) === str_repeat($quote, 3)) {
            $end = strpos($this->text, str_repeat($quote, 3), $this->at + 3);

            if ($end === false) {
                return null;
            }

            $raw = (string) preg_replace('/^\r?\n/', '', substr($this->text, $this->at + 3, $end - $this->at - 3));
            $this->at = $end + 3;

            return $quote === '"' ? self::unescape($raw) : $raw;
        }

        for ($i = $this->at + 1; $i < $this->length; $i++) {
            $char = $this->text[$i];

            if ($char === "\n") {
                return null;
            }

            if ($char === '\\' && $quote === '"') {
                $i++;

                continue;
            }

            if ($char === $quote) {
                $raw = substr($this->text, $this->at + 1, $i - $this->at - 1);
                $this->at = $i + 1;

                return $quote === '"' ? self::unescape($raw) : $raw;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function array(): ?array
    {
        $this->at++;
        $items = [];

        while ($this->skipBlank() < $this->length) {
            if ($this->text[$this->at] === ']') {
                $this->at++;

                return $items;
            }

            $quoted = $this->text[$this->at] === '"' || $this->text[$this->at] === "'";
            $item = $this->value(true);

            if ($item === null) {
                return null;
            }

            if ($quoted && is_string($item)) {
                $items[] = $item;
            }

            if ($this->skipBlank() < $this->length && $this->text[$this->at] === ',') {
                $this->at++;
            }
        }

        return null;
    }

    private function inlineTable(): ?string
    {
        $depth = 0;
        $quote = null;

        for ($i = $this->at; $i < $this->length; $i++) {
            $char = $this->text[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif (($char === '}' || $char === ']') && --$depth === 0) {
                $raw = substr($this->text, $this->at, $i - $this->at + 1);
                $this->at = $i + 1;

                return $raw;
            }
        }

        return null;
    }

    private function bare(bool $inArray): ?string
    {
        $stops = $inArray ? "\n#,]" : "\n#";
        $end = $this->at + strcspn($this->text, $stops, $this->at);
        $raw = trim(substr($this->text, $this->at, $end - $this->at));
        $this->at = $end;

        return $raw === '' ? null : $raw;
    }

    private function skipBlank(): int
    {
        while ($this->at < $this->length) {
            $char = $this->text[$this->at];

            if ($char === '#') {
                $this->at += strcspn($this->text, "\n", $this->at);
            } elseif (ctype_space($char)) {
                $this->at++;
            } else {
                break;
            }
        }

        return $this->at;
    }

    private function skipLine(): void
    {
        $this->at += strcspn($this->text, "\n", $this->at);
    }

    private static function key(string $raw): string
    {
        $parts = [''];
        $quote = null;

        foreach (str_split($raw) as $char) {
            if ($quote === null && $char === '.') {
                $parts[] = '';

                continue;
            }

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            }

            $parts[count($parts) - 1] .= $char;
        }

        return implode('.', array_map(function (string $part): string {
            $part = trim($part);
            $quoted = strlen($part) >= 2 && ($part[0] === '"' || $part[0] === "'") && $part[strlen($part) - 1] === $part[0];

            if (!$quoted) {
                return $part;
            }

            return $part[0] === '"' ? self::unescape(substr($part, 1, -1)) : substr($part, 1, -1);
        }, $parts));
    }

    private static function unescape(string $raw): string
    {
        return (string) preg_replace_callback('/\\\\(?:u([0-9A-Fa-f]{4})|U([0-9A-Fa-f]{8})|x([0-9A-Fa-f]{2})|(.))/s', function (array $match): string {
            $hex = ($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? '');

            if ($hex !== '') {
                return (string) mb_chr((int) hexdec($hex), 'UTF-8');
            }

            return match ($match[4] ?? '') {
                'b' => "\x08",
                't' => "\t",
                'n' => "\n",
                'f' => "\f",
                'r' => "\r",
                'e' => "\e",
                '"' => '"',
                '\\' => '\\',
                default => '\\' . ($match[4] ?? ''),
            };
        }, $raw);
    }
}
