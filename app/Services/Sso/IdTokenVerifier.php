<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use Throwable;

final class IdTokenVerifier
{
    public const LEEWAY = 60;

    public const MINIMUM_RSA_BITS = 2048;

    /** @var array<string, string> */
    public const ALGORITHMS = ['RS256' => 'RSA', 'ES256' => 'EC'];

    private const REJECTED = 'The ID token could not be verified.';

    public function __construct(private readonly OidcClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token, ProviderMetadata $provider, string $clientId, string $nonce): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new SsoException(self::REJECTED, 'id_token.format');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedClaims);
        $signature = $this->decode($encodedSignature);

        $algorithm = $header['alg'] ?? null;

        if (!is_string($algorithm) || !array_key_exists($algorithm, self::ALGORITHMS) || array_key_exists('crit', $header)) {
            throw new SsoException(self::REJECTED, 'id_token.alg');
        }

        $kid = $header['kid'] ?? null;

        if ($kid !== null && (!is_string($kid) || $kid === '')) {
            throw new SsoException(self::REJECTED, 'id_token.key');
        }

        $key = $this->key($provider, $algorithm, $kid);

        if (!$this->signatureMatches($key, $algorithm, $encodedHeader . '.' . $encodedClaims, $signature)) {
            throw new SsoException(self::REJECTED, 'id_token.signature');
        }

        $this->assertClaims($claims, $provider, $clientId, $nonce);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaims(array $claims, ProviderMetadata $provider, string $clientId, string $nonce): void
    {
        $now = now()->getTimestamp();

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw new SsoException(self::REJECTED, 'id_token.iss');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = match (true) {
            is_string($audience) => [$audience],
            is_array($audience) && array_is_list($audience) => array_values(array_unique(array_filter($audience, is_string(...)))),
            default => [],
        };

        if (!in_array($clientId, $audiences, true)) {
            throw new SsoException(self::REJECTED, 'id_token.aud');
        }

        $authorizedParty = $claims['azp'] ?? null;

        if ((count($audiences) > 1 && $authorizedParty === null) || ($authorizedParty !== null && $authorizedParty !== $clientId)) {
            throw new SsoException(self::REJECTED, 'id_token.azp');
        }

        $expires = $claims['exp'] ?? null;

        if (!self::isNumber($expires) || $expires + self::LEEWAY <= $now) {
            throw new SsoException(self::REJECTED, 'id_token.exp');
        }

        $issued = $claims['iat'] ?? null;

        if (!self::isNumber($issued) || $issued - self::LEEWAY > $now) {
            throw new SsoException(self::REJECTED, 'id_token.iat');
        }

        $notBefore = $claims['nbf'] ?? null;

        if ($notBefore !== null && (!self::isNumber($notBefore) || $notBefore - self::LEEWAY > $now)) {
            throw new SsoException(self::REJECTED, 'id_token.nbf');
        }

        $tokenNonce = $claims['nonce'] ?? null;

        if (!is_string($tokenNonce) || $nonce === '' || !hash_equals($nonce, $tokenNonce)) {
            throw new SsoException(self::REJECTED, 'id_token.nonce');
        }

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new SsoException(self::REJECTED, 'id_token.sub');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function key(ProviderMetadata $provider, string $algorithm, ?string $kid): array
    {
        $key = $this->pick($this->client->keys($provider), $algorithm, $kid)
            ?? $this->pick($this->client->keys($provider, fresh: true), $algorithm, $kid);

        if ($key === null) {
            throw new SsoException(self::REJECTED, 'id_token.key');
        }

        return $key;
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private function pick(array $keys, string $algorithm, ?string $kid): ?array
    {
        $candidates = array_values(array_filter($keys, fn(array $key): bool => ($key['kty'] ?? null) === self::ALGORITHMS[$algorithm]
            && ($algorithm !== 'ES256' || ($key['crv'] ?? null) === 'P-256')
            && (!array_key_exists('use', $key) || $key['use'] === 'sig')
            && (!array_key_exists('alg', $key) || $key['alg'] === $algorithm)
            && (!array_key_exists('key_ops', $key) || (is_array($key['key_ops']) && in_array('verify', $key['key_ops'], true)))
            && ($kid === null || ($key['kid'] ?? null) === $kid)));

        if ($kid === null) {
            return count($candidates) === 1 ? $candidates[0] : null;
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function signatureMatches(array $jwk, string $algorithm, string $input, string $signature): bool
    {
        $members = $algorithm === 'RS256' ? ['n', 'e'] : ['x', 'y'];

        foreach ($members as $member) {
            if (!is_string($jwk[$member] ?? null) || $jwk[$member] === '') {
                return false;
            }
        }

        try {
            if ($algorithm === 'RS256') {
                $key = RSA::loadFormat('JWK', (string) json_encode(['keys' => [['kty' => 'RSA', 'n' => $jwk['n'], 'e' => $jwk['e']]]]));

                return $key instanceof RSA\PublicKey
                    && $key->getLength() >= self::MINIMUM_RSA_BITS
                    && $key->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->verify($input, $signature);
            }

            $key = EC::loadFormat('JWK', (string) json_encode(['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']]]]));

            return strlen($signature) === 64
                && $key instanceof EC\PublicKey
                && $key->withSignatureFormat('IEEE')->withHash('sha256')->verify($input, $signature);
        } catch (Throwable) {
            return false;
        }
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $decoded = json_decode($this->decode($segment), true);

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new SsoException(self::REJECTED, 'id_token.format');
        }

        return $decoded;
    }

    private function decode(string $segment): string
    {
        $decoded = preg_match('/^[A-Za-z0-9_-]*$/', $segment) === 1 ? base64_decode(strtr($segment, '-_', '+/'), true) : false;

        if ($decoded === false) {
            throw new SsoException(self::REJECTED, 'id_token.format');
        }

        return $decoded;
    }
}
