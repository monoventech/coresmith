<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use App\Services\Network\AddressResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

function ssoRsaKey(): RSA\PrivateKey
{
    static $key = null;

    return $key ??= RSA::createKey(2048);
}

function ssoForeignRsaKey(): RSA\PrivateKey
{
    static $key = null;

    return $key ??= RSA::createKey(2048);
}

function ssoEcKey(): EC\PrivateKey
{
    static $key = null;

    return $key ??= EC::createKey('secp256r1');
}

function ssoBase64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ssoJwks(): array
{
    $rsa = json_decode(ssoRsaKey()->getPublicKey()->toString('JWK'), true)['keys'][0];
    $ec = json_decode(ssoEcKey()->getPublicKey()->toString('JWK'), true)['keys'][0];

    return ['keys' => [
        $rsa + ['kid' => 'rsa-1', 'use' => 'sig', 'alg' => 'RS256'],
        $ec + ['kid' => 'ec-1', 'use' => 'sig', 'alg' => 'ES256'],
    ]];
}

function ssoToken(array $claims, array $header = [], RSA\PrivateKey|EC\PrivateKey|null $key = null): string
{
    $header = array_merge(['alg' => 'RS256', 'kid' => 'rsa-1', 'typ' => 'JWT'], $header);
    $input = ssoBase64((string) json_encode($header)) . '.' . ssoBase64((string) json_encode($claims));

    $signature = match ($header['alg']) {
        'RS256' => ($key ?? ssoRsaKey())->withPadding(RSA::SIGNATURE_PKCS1)->withHash('sha256')->sign($input),
        'ES256' => ($key ?? ssoEcKey())->withSignatureFormat('IEEE')->withHash('sha256')->sign($input),
        'HS256' => hash_hmac('sha256', $input, 'client-secret', true),
        default => '',
    };

    return $input . '.' . ssoBase64($signature);
}

function ssoClaims(string $nonce, array $overrides = []): array
{
    $claims = array_merge([
        'iss' => 'https://idp.example.test',
        'sub' => 'user-123',
        'aud' => 'coresmith-client',
        'exp' => now()->addMinutes(5)->getTimestamp(),
        'iat' => now()->getTimestamp(),
        'nonce' => $nonce,
        'email' => 'ada@example.test',
        'email_verified' => true,
        'name' => 'Ada Lovelace',
    ], $overrides);

    return array_filter($claims, fn(mixed $value): bool => $value !== null);
}

function ssoFakeNetwork(array $addresses = [], array $txt = []): void
{
    app()->instance(AddressResolver::class, new class($addresses) implements AddressResolver
    {
        public function __construct(private readonly array $map) {}

        public function addresses(string $host): array
        {
            return $this->map[$host] ?? ['203.0.113.10'];
        }
    });

    app()->instance(DnsResolver::class, new class($txt) implements DnsResolver
    {
        public function __construct(private readonly array $records) {}

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    });
}

function ssoDiscovery(array $overrides = []): array
{
    return array_merge([
        'issuer' => 'https://idp.example.test',
        'authorization_endpoint' => 'https://idp.example.test/authorize',
        'token_endpoint' => 'https://idp.example.test/token',
        'jwks_uri' => 'https://idp.example.test/jwks',
        'userinfo_endpoint' => 'https://idp.example.test/userinfo',
    ], $overrides);
}

function ssoFakeProvider(array $discovery = []): void
{
    Http::fake([
        'https://idp.example.test/.well-known/openid-configuration' => Http::response(ssoDiscovery($discovery)),
        'https://idp.example.test/jwks' => Http::response(ssoJwks()),
    ]);
}

function ssoFakeUserinfo(array $body): void
{
    Http::fake(['https://idp.example.test/userinfo' => Http::response($body)]);
}

function ssoTokenResponse(?array $response = null): array
{
    static $current = [];

    if ($response !== null) {
        $current = $response;
    }

    return $current;
}

function ssoFakeToken(string $idToken, array $extra = []): void
{
    ssoTokenResponse(array_merge(['id_token' => $idToken, 'access_token' => 'access-token', 'token_type' => 'Bearer'], $extra));

    Http::fake(['https://idp.example.test/token' => fn() => Http::response(ssoTokenResponse())]);
}

function ssoTeam(string $slug = 'acme'): Team
{
    return Team::factory()->create(['name' => 'Acme Labs', 'slug' => $slug]);
}

function ssoMember(Team $team, TeamRole $role = TeamRole::Member, array $attributes = [], ?User $user = null): User
{
    $user ??= User::factory()->create($attributes);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function ssoLinkedMember(Team $team, array $attributes = ['email' => 'ada@example.test'], string $subject = 'user-123', ?User $user = null): User
{
    $member = ssoMember($team, TeamRole::Member, $attributes, $user);

    SsoIdentity::factory()->create([
        'user_id' => $member->id,
        'team_id' => $team->id,
        'issuer' => 'https://idp.example.test',
        'subject' => $subject,
        'email' => Str::lower($member->email),
    ]);

    return $member;
}

function ssoInertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

function ssoConnection(Team $team, array $attributes = []): TeamSsoConnection
{
    return TeamSsoConnection::factory()->for($team)->enabled()->create(array_merge([
        'issuer' => 'https://idp.example.test',
        'client_id' => 'coresmith-client',
        'client_secret' => 'client-secret',
        'email_domain' => 'example.test',
    ], $attributes));
}

function ssoStart(string $url = 'http://localhost/sso/acme/start'): array
{
    $location = (string) test()->get($url)->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query + ['location' => $location];
}

function ssoCallback(string $state, string $host = 'http://localhost', array $query = []): TestResponse
{
    return test()->get($host . '/sso/callback?' . http_build_query(array_merge(['state' => $state, 'code' => 'auth-code'], $query)));
}

function ssoSignIn(array $overrides = [], array $header = [], RSA\PrivateKey|EC\PrivateKey|null $key = null, string $host = 'http://localhost', string $slug = 'acme'): TestResponse
{
    $flow = ssoStart($host . '/sso/' . $slug . '/start');

    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'], $overrides), $header, $key));

    return ssoCallback($flow['state'], $host);
}
