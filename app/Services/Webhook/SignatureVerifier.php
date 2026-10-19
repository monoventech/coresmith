<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Webhook;

use Illuminate\Http\Request;

final class SignatureVerifier
{
    public function verify(Request $request, string $provider, #[\SensitiveParameter] string $secret): bool
    {
        $signature = $this->presentedSignature($request, $provider);

        if ($signature === null || $secret === '') {
            return false;
        }

        return hash_equals($this->expected($request->getContent(), $provider, $secret), $signature);
    }

    public function expected(string $payload, string $provider, #[\SensitiveParameter] string $secret): string
    {
        $digest = hash_hmac('sha256', $payload, $secret);

        return match ($provider) {
            'github' => 'sha256=' . $digest,
            'bitbucket' => 'sha256=' . $digest,
            default => $digest,
        };
    }

    private function presentedSignature(Request $request, string $provider): ?string
    {
        $header = match ($provider) {
            'github' => 'X-Hub-Signature-256',
            'gitlab' => 'X-Gitlab-Token',
            'bitbucket' => 'X-Hub-Signature',
            default => 'X-Coresmith-Signature',
        };

        $value = $request->header($header);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function branchFromPayload(Request $request): ?string
    {
        $ref = $request->input('ref');

        if (is_string($ref) && str_starts_with($ref, 'refs/heads/')) {
            return substr($ref, strlen('refs/heads/'));
        }

        $gitlabBranch = $request->input('object_attributes.target_branch');

        return is_string($gitlabBranch) ? $gitlabBranch : null;
    }
}
