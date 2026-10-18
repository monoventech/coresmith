<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Exceptions\GitProviderException;
use phpseclib3\Crypt\RSA;
use phpseclib3\Exception\NoKeyLoadedException;

final class AppJwt
{
    public function for(#[\SensitiveParameter] GitHubAppCredentials $app): string
    {
        if ($app->privateKey === '' || $app->appId === '') {
            throw new GitProviderException('This GitHub connection is missing its App credentials.');
        }

        try {
            $key = RSA::loadPrivateKey($app->privateKey);
        } catch (NoKeyLoadedException) {
            throw new GitProviderException('This GitHub connection\'s App private key could not be read. Check the App credentials.');
        }

        $now = time();

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $app->appId,
        ], JSON_THROW_ON_ERROR));

        $signature = $key
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
