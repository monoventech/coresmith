<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\SSH\SSHConnection;

final class EnvironmentFile
{
    private const KEY = '[A-Za-z_][A-Za-z0-9_]*';

    private const DOUBLE_QUOTE_ESCAPES = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\', '$' => '$'];

    public function write(SSHConnection $connection, Application $application): void
    {
        $path = (new ReleasePaths($application))->envFile();
        $directory = escapeshellarg(dirname($path));
        $file = escapeshellarg($path);

        $connection->execute("mkdir -p {$directory} && chmod 700 {$directory} && install -m 600 -o root -g root /dev/null {$file}", sudo: true)->throw();
        $connection->uploadContent($this->render($this->parse((string) $application->env_variables)), $path);
        $connection->execute("chown root:root {$file} && chmod 600 {$file}", sudo: true)->throw();
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function render(array $variables): string
    {
        $lines = [];

        foreach ($variables as $key => $value) {
            $lines[] = $key . '="' . addcslashes($value, '\\"$`') . '"';
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $raw): array
    {
        return $this->read($raw)['variables'];
    }

    public function firstError(string $raw): ?string
    {
        return $this->read($raw)['error'];
    }

    /**
     * @return array{variables: array<string, string>, error: ?string}
     */
    private function read(string $raw): array
    {
        $json = $this->json($raw);

        if ($json !== null) {
            return ['variables' => $json, 'error' => null];
        }

        $lines = preg_split('/\R/', $raw) ?: [];
        $variables = [];
        $error = null;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $number = $index + 1;
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\s*(?:export\s+)?(' . self::KEY . ')\s*=\s*(.*)$/s', $line, $matches) !== 1) {
                $error ??= "Line {$number} is not KEY=value. Fix it or remove it.";

                continue;
            }

            [, $key, $value] = $matches;

            if (array_key_exists($key, $variables)) {
                $error ??= "Line {$number} sets {$key} again. Remove one of them.";
            }

            $quote = $value[0] ?? '';

            if ($quote === '"' || $quote === "'") {
                $parsed = $this->quoted($lines, $index, substr($value, 1), $quote);

                if ($parsed === null) {
                    $error ??= "Line {$number} opens a quote that never closes. Close it or remove it.";

                    break;
                }

                [$value, $rest, $index] = $parsed;

                if ($rest !== '' && !str_starts_with($rest, '#')) {
                    $error ??= "Line {$number} has text after its closing quote. Remove it or move it inside the quotes.";
                }

                $variables[$key] = $quote === '"' ? $this->interpolate($value, $variables) : $value;

                continue;
            }

            $variables[$key] = $this->interpolate(trim((string) preg_replace('/\s+#.*$/', '', $value)), $variables);
        }

        return ['variables' => $variables, 'error' => $error];
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: string, 1: string, 2: int}|null
     */
    private function quoted(array $lines, int $index, string $text, string $quote): ?array
    {
        $value = '';

        while (true) {
            $length = strlen($text);

            for ($position = 0; $position < $length; $position++) {
                $character = $text[$position];

                if ($quote === '"' && $character === '\\' && $position + 1 < $length) {
                    $next = $text[$position + 1];
                    $value .= self::DOUBLE_QUOTE_ESCAPES[$next] ?? '\\' . $next;
                    $position++;

                    continue;
                }

                if ($character === $quote) {
                    return [$value, trim(substr($text, $position + 1)), $index];
                }

                $value .= $character;
            }

            $index++;

            if ($index >= count($lines)) {
                return null;
            }

            $value .= "\n";
            $text = $lines[$index];
        }
    }

    /**
     * @param  array<string, string>  $known
     */
    private function interpolate(string $value, array $known): string
    {
        return (string) preg_replace_callback(
            '/\$\{(' . self::KEY . ')\}/',
            static fn(array $match): string => $known[$match[1]] ?? $match[0],
            $value,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function json(string $raw): ?array
    {
        if (!str_starts_with(ltrim($raw), '{')) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $variables = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && preg_match('/^' . self::KEY . '$/', $key) === 1 && is_scalar($value)) {
                $variables[$key] = (string) $value;
            }
        }

        return $variables;
    }
}
