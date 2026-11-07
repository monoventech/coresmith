<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Services\Teams\TeamContext;
use Illuminate\Http\Request;

final class SignIn
{
    public const SCOPES = 'openid email profile';

    public const CALLBACK_PATH = '/sso/callback';

    public const UNREACHABLE = 'Coresmith could not reach your identity provider. Try again in a few minutes, or sign in with your password.';

    public const REFUSED = 'Your identity provider would not finish signing you in. Start again, or ask your administrator to check the single sign-on settings.';

    public const UNVERIFIED = 'Coresmith could not verify the response from your identity provider. Start again, or ask your administrator to check the single sign-on settings.';

    public const NO_EMAIL = 'Your identity provider did not share an email address. Ask your administrator to allow the email scope, then try again.';

    public function __construct(
        private readonly OidcClient $client,
        private readonly IdTokenVerifier $verifier,
        private readonly SignInState $state,
        private readonly AccountLinker $accounts,
        private readonly TeamContext $context,
    ) {}

    public function authorizationUrl(Request $request, Team $team, TeamSsoConnection $connection): string
    {
        try {
            $provider = $this->client->discover($connection->issuer);
        } catch (SsoException $e) {
            throw new SsoException(self::UNREACHABLE, $e->reason, $team);
        }

        $host = $request->getSchemeAndHttpHost();
        $flow = $this->state->begin($request, $team, $host);

        $query = http_build_query([
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'client_id' => $connection->client_id,
            'redirect_uri' => $host . self::CALLBACK_PATH,
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $flow['challenge'],
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        $endpoint = $provider->authorizationEndpoint;

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    }

    public function complete(Request $request): SsoIdentity
    {
        $flow = $this->state->consume($request, $request->query('state'));

        if ($flow === null) {
            throw new SsoException('That sign-in attempt has expired or was already used. Start again from the sign-in page.', 'state');
        }

        $team = Team::query()->find($flow['team']);
        $connection = $team === null ? null : TeamSsoConnection::query()->where('team_id', $team->getKey())->first();

        if (!$team instanceof Team || !$connection instanceof TeamSsoConnection || !$connection->isUsable()) {
            throw new SsoException('Single sign-on is not turned on for that team any more. Sign in with your password.', 'disabled', $team);
        }

        if (!hash_equals($flow['host'], $request->getSchemeAndHttpHost()) || ($this->context->viaHost() && $this->context->team()?->getKey() !== $team->getKey())) {
            throw new SsoException('That sign-in attempt started on a different address. Start again from the sign-in page.', 'host', $team);
        }

        if ($request->query('error') !== null) {
            throw new SsoException('Your identity provider did not sign you in. Try again, or ask your administrator for access.', 'denied', $team);
        }

        $code = $request->query('code');

        if (!is_string($code) || $code === '' || strlen($code) > 4096) {
            throw new SsoException('Your identity provider did not send a sign-in code. Start again from the sign-in page.', 'code', $team);
        }

        try {
            $provider = $this->client->discover($connection->issuer);
            $tokens = $this->client->exchange($provider, $connection, $code, $flow['verifier'], $flow['host'] . self::CALLBACK_PATH);
            $claims = $this->verifier->verify($tokens['id_token'], $provider, $connection->client_id, $flow['nonce']);
            $profile = $this->profile($provider, $claims, $tokens['access_token']);
        } catch (SsoException $e) {
            throw new SsoException($this->publicMessage($e), $e->reason, $team);
        }

        return $this->accounts->resolve($connection, $team, $provider->issuer, $profile);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{sub: string, email: string, email_verified: mixed, name: mixed}
     */
    private function profile(ProviderMetadata $provider, array $claims, ?string $accessToken): array
    {
        $subject = (string) $claims['sub'];
        $email = $claims['email'] ?? null;
        $verified = $claims['email_verified'] ?? null;
        $name = $claims['name'] ?? null;

        if ((!is_string($email) || trim($email) === '') && $accessToken !== null && $provider->userinfoEndpoint !== null) {
            $info = $this->client->userinfo($provider, $accessToken);

            if (($info['sub'] ?? null) !== $subject) {
                throw new SsoException(self::UNVERIFIED, 'userinfo.sub');
            }

            $email = $info['email'] ?? null;
            $verified = $info['email_verified'] ?? null;
            $name = is_string($name) && $name !== '' ? $name : ($info['name'] ?? null);
        }

        if (!is_string($email) || trim($email) === '') {
            throw new SsoException(self::NO_EMAIL, 'email.missing');
        }

        return ['sub' => $subject, 'email' => $email, 'email_verified' => $verified, 'name' => $name];
    }

    private function publicMessage(SsoException $e): string
    {
        return match (true) {
            str_starts_with($e->reason, 'id_token'), $e->reason === 'userinfo.sub' => self::UNVERIFIED,
            str_starts_with($e->reason, 'token') => self::REFUSED,
            in_array($e->reason, ['userinfo', 'email.missing'], true) => self::NO_EMAIL,
            default => self::UNREACHABLE,
        };
    }
}
