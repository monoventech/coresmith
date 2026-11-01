<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\ReleaseSignature;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final class TagSignature
{
    private const TIMEOUT = 30;

    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly LogSanitizer $sanitizer,
    ) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = glob($this->paths->keys() . '/*.asc') ?: [];
        sort($keys);

        return array_values(array_filter($keys, 'is_file'));
    }

    public function verify(string $repository, string $tag): SignatureResult
    {
        $keys = $this->keys();

        if ($keys === []) {
            return new SignatureResult(ReleaseSignature::Unchecked, "Release signatures aren't checked: this install has no Coresmith release keys yet.");
        }

        $home = rtrim(sys_get_temp_dir(), '/') . '/csg-' . Str::lower(Str::random(8));

        if (!@mkdir($home, 0700)) {
            throw new RuntimeException("Coresmith couldn't create a keyring in {$home}.");
        }

        try {
            foreach ($keys as $key) {
                $import = Process::timeout(self::TIMEOUT)->run(['gpg', '--batch', '--quiet', '--homedir', $home, '--import', $key]);

                if (!$import->successful()) {
                    return new SignatureResult(ReleaseSignature::Invalid, "The release keys in {$this->paths->keys()} couldn't be read: " . $this->lastLine($import->errorOutput() . $import->output()));
                }
            }

            $result = Process::path($repository)
                ->env(['GNUPGHOME' => $home, 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'])
                ->timeout(self::TIMEOUT)
                ->run(['git', 'verify-tag', '--raw', $tag]);

            return $this->decide($tag, $result->output() . "\n" . $result->errorOutput(), $result->successful());
        } finally {
            Process::timeout(self::TIMEOUT)->run(['gpgconf', '--homedir', $home, '--kill', 'all']);
            File::deleteDirectory($home);
        }
    }

    private function decide(string $tag, string $output, bool $succeeded): SignatureResult
    {
        $invalid = fn(string $message): SignatureResult => new SignatureResult(ReleaseSignature::Invalid, $message);

        return match (true) {
            str_contains($output, 'no signature found'), str_contains($output, 'cannot verify a non-tag object') => $invalid("{$tag} is not signed. This install only accepts releases signed with a Coresmith release key."),
            $this->status($output, 'NO_PUBKEY') !== null, $this->status($output, 'ERRSIG') !== null => $invalid("{$tag} is signed with a key this install doesn't trust."),
            $this->status($output, 'BADSIG') !== null => $invalid("{$tag}'s signature doesn't match its contents. It may have been tampered with."),
            $this->status($output, 'EXPKEYSIG') !== null, $this->status($output, 'REVKEYSIG') !== null, $this->status($output, 'EXPSIG') !== null => $invalid("{$tag} is signed with a key that has expired or been revoked."),
            $succeeded && $this->status($output, 'GOODSIG') !== null && ($valid = $this->status($output, 'VALIDSIG')) !== null => $this->verified($valid),
            default => $invalid("Coresmith couldn't verify {$tag}'s signature: " . $this->lastLine($output)),
        };
    }

    /**
     * @param  list<string>  $fields
     */
    private function verified(array $fields): SignatureResult
    {
        $fingerprint = strtoupper($fields[9] ?? $fields[0] ?? '');
        $groups = str_split($fingerprint, 4);
        $grouped = implode(' ', array_slice($groups, 0, 5)) . '  ' . implode(' ', array_slice($groups, 5));

        return new SignatureResult(ReleaseSignature::Verified, 'Signed by ' . trim($grouped) . '.', $fingerprint);
    }

    /**
     * @return list<string>|null
     */
    private function status(string $output, string $keyword): ?array
    {
        if (preg_match('/^\[GNUPG:\] ' . $keyword . '(?: (.*))?$/m', $output, $match) !== 1) {
            return null;
        }

        return array_values(array_filter(explode(' ', trim($match[1] ?? '')), fn(string $field): bool => $field !== ''));
    }

    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn(string $line): bool => $line !== ''));

        return $this->sanitizer->sanitize(Str::limit($lines[count($lines) - 1] ?? 'gpg gave no reason.', 300));
    }
}
