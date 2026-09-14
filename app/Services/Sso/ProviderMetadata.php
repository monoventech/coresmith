<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

final readonly class ProviderMetadata
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint,
    ) {}

    /**
     * @param  array<string, string|null>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) $values['issuer'],
            (string) $values['authorization_endpoint'],
            (string) $values['token_endpoint'],
            (string) $values['jwks_uri'],
            $values['userinfo_endpoint'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'userinfo_endpoint' => $this->userinfoEndpoint,
        ];
    }
}
