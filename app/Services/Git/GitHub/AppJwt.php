<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitProviderException;
use phpseclib3\Crypt\RSA;

final class AppJwt
{
    public function for(GitHubAppCredentials $app): string
    {
        if ($app->privateKey === '' || $app->appId === '') {
            throw new GitProviderException('This GitHub connection is missing its App credentials.');
        }

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iat' => time() - 60,
            'exp' => time() + 540,
            'iss' => $app->appId,
        ], JSON_THROW_ON_ERROR));

        $signature = RSA::loadPrivateKey($app->privateKey)
            ->withHash('sha256')
            ->withPadding(RSA::SIGNATURE_PKCS1)
            ->sign($header . '.' . $claims);

        return $header . '.' . $claims . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
