<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Exceptions\VaultDecryptionException;
use App\Models\TeamSsoConnection;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class OidcClient
{
    public const TIMEOUT = 10;

    public const CACHE_SECONDS = 600;

    public const DISCOVERY_PATH = '/.well-known/openid-configuration';

    public function __construct(private readonly UrlGuard $guard) {}

    public static function withoutTrailingSlash(string $url): string
    {
        return str_ends_with($url, '/') ? substr($url, 0, -1) : $url;
    }

    public static function discoveryUrl(string $issuer): string
    {
        return self::withoutTrailingSlash($issuer) . self::DISCOVERY_PATH;
    }

    public function discover(string $issuer, bool $fresh = false): ProviderMetadata
    {
        $key = 'sso:discovery:' . hash('sha256', $issuer);

        if ($fresh) {
            Cache::forget($key);
        }

        return ProviderMetadata::fromArray(Cache::remember($key, self::CACHE_SECONDS, fn(): array => $this->fetchDiscovery($issuer)->toArray()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function keys(ProviderMetadata $provider, bool $fresh = false): array
    {
        $key = 'sso:jwks:' . hash('sha256', $provider->jwksUri);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($provider): array {
            $document = $this->json($this->get($provider->jwksUri), 'The identity provider did not return its signing keys.', 'jwks');
            $keys = $document['keys'] ?? null;

            if (!is_array($keys) || !array_is_list($keys)) {
                throw new SsoException('The identity provider did not return its signing keys.', 'jwks');
            }

            return array_values(array_filter($keys, is_array(...)));
        });
    }

    /**
     * @return array{id_token: string, access_token: string|null}
     */
    public function exchange(ProviderMetadata $provider, TeamSsoConnection $connection, string $code, string $verifier, string $redirectUri): array
    {
        try {
            $secret = (string) $connection->client_secret;
        } catch (VaultDecryptionException) {
            throw new SsoException('The saved client secret could not be read. Save it again in team settings.', 'token.secret');
        }

        $client = $this->client($provider->tokenEndpoint)->asForm()->acceptJson();

        $response = $this->send(fn(): Response => $client->post($provider->tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $connection->client_id,
            'client_secret' => $secret,
            'code_verifier' => $verifier,
        ]));

        $data = $this->json($response, 'The identity provider would not exchange the sign-in code.', 'token');
        $idToken = $data['id_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new SsoException('The identity provider did not send an ID token.', 'token');
        }

        return ['id_token' => $idToken, 'access_token' => is_string($accessToken) && $accessToken !== '' ? $accessToken : null];
    }

    /**
     * @return array<string, mixed>
     */
    public function userinfo(ProviderMetadata $provider, string $accessToken): array
    {
        if ($provider->userinfoEndpoint === null) {
            throw new SsoException('The identity provider has no userinfo endpoint.', 'userinfo');
        }

        return $this->json($this->get($provider->userinfoEndpoint, $accessToken), 'The identity provider did not share the account details.', 'userinfo');
    }

    private function fetchDiscovery(string $issuer): ProviderMetadata
    {
        $document = $this->json(
            $this->get(self::discoveryUrl($issuer)),
            'There is no OpenID configuration at that issuer. Check the issuer URL, then try again.',
            'discovery',
        );

        $documentIssuer = $document['issuer'] ?? null;

        if (!is_string($documentIssuer) || self::withoutTrailingSlash($documentIssuer) !== self::withoutTrailingSlash($issuer)) {
            $named = is_string($documentIssuer) ? ' It names ' . Str::limit($documentIssuer, 200) . ', use that exact URL.' : '';

            throw new SsoException('The OpenID configuration belongs to a different issuer.' . $named, 'discovery.issuer');
        }

        $endpoints = [];

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $name) {
            $value = $document[$name] ?? null;

            if ($value === null && $name === 'userinfo_endpoint') {
                $endpoints[$name] = null;

                continue;
            }

            if (!is_string($value) || $value === '') {
                throw new SsoException("The OpenID configuration has no {$name}. Check your identity provider's settings.", 'discovery.endpoint');
            }

            $refusal = $this->guard->refusal($value);

            if ($refusal !== null) {
                throw new SsoException("The OpenID configuration lists a {$name} Coresmith will not use. {$refusal}", 'discovery.endpoint');
            }

            $endpoints[$name] = $value;
        }

        return new ProviderMetadata(
            $documentIssuer,
            (string) $endpoints['authorization_endpoint'],
            (string) $endpoints['token_endpoint'],
            (string) $endpoints['jwks_uri'],
            $endpoints['userinfo_endpoint'],
        );
    }

    private function get(string $url, ?string $token = null): Response
    {
        $client = $this->client($url)->acceptJson();

        if ($token !== null) {
            $client = $client->withToken($token);
        }

        return $this->send(fn(): Response => $client->get($url));
    }

    private function client(string $url): PendingRequest
    {
        return Http::withOptions($this->guard->options($url))
            ->withoutRedirecting()
            ->connectTimeout(self::TIMEOUT)
            ->timeout(self::TIMEOUT);
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw new SsoException('Coresmith could not reach the identity provider. Check the issuer URL, then try again.', 'unreachable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response, string $failure, string $reason): array
    {
        if ($response->status() !== 200) {
            throw new SsoException($failure, $reason);
        }

        $data = json_decode($response->body(), true);

        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new SsoException($failure, $reason);
        }

        return $data;
    }
}
