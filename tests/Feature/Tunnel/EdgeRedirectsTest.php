<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Tunnel\EdgeRedirects;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSSHTransport;

function edgeRedirectsConnection(array $origin = []): object
{
    $transport = new FakeSSHTransport;

    foreach ($origin as $host => $redirect) {
        $transport->respondTo("{$host}:443:127.0.0.1", $redirect);
    }

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return app(SSHConnectionManager::class)->for(Server::factory()->create(['host_key_fingerprint' => null]));
}

function edgeRedirectsCloudflare(array $edge, array $addresses = []): void
{
    Http::fake(function (Request $request) use ($edge, $addresses) {
        $url = $request->url();

        if (str_starts_with($url, 'https://cloudflare-dns.com/dns-query')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $name = (string) ($query['name'] ?? '');
            $type = (string) ($query['type'] ?? '');
            $answers = array_values(array_filter($addresses[$name] ?? [['type' => 1, 'data' => '104.21.84.132']], fn(array $answer): bool => $answer['type'] === ($type === 'AAAA' ? 28 : 1)));

            return Http::response(['Status' => 0, 'Answer' => array_map(fn(array $answer): array => ['name' => $name, ...$answer], $answers)]);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return isset($edge[$host]) ? Http::response('', 301, ['Location' => $edge[$host]]) : Http::response('ok');
    });
}

test('a domain Cloudflare redirects to another site while the server answers it is named with where it goes', function () {
    edgeRedirectsCloudflare(['monoven.com' => 'https://cloudflare.vc/', 'notes.example.com' => 'https://cloudflare.vc/']);
    $connection = edgeRedirectsConnection(['monoven.com' => '', 'notes.example.com' => '']);

    expect(app(EdgeRedirects::class)->find($connection, ['monoven.com', 'notes.example.com', 'beelink.example.com']))->toBe([
        ['host' => 'monoven.com', 'to' => 'cloudflare.vc'],
        ['host' => 'notes.example.com', 'to' => 'cloudflare.vc'],
    ]);

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://monoven.com/');
});

test("an app's own redirect, one on the same domain, or one to its www name is never blamed on Cloudflare", function (string $edge, string $origin) {
    edgeRedirectsCloudflare(['monoven.com' => $edge]);
    $connection = edgeRedirectsConnection(['monoven.com' => $origin]);

    expect(app(EdgeRedirects::class)->find($connection, ['monoven.com']))->toBe([]);
})->with([
    'the app sends people to sign in elsewhere' => ['https://accounts.example.net/login', 'https://accounts.example.net/login?next=%2F'],
    'a page on the same domain' => ['https://monoven.com/login', 'https://monoven.com/login'],
    'a path only' => ['/login', '/login'],
    'the www name' => ['https://www.monoven.com/', ''],
]);

test('a domain whose DNS does not answer from Cloudflare is not checked through it', function () {
    edgeRedirectsCloudflare(['monoven.com' => 'https://cloudflare.vc/'], ['monoven.com' => [['type' => 1, 'data' => '151.226.169.196']]]);
    $connection = edgeRedirectsConnection(['monoven.com' => '']);

    expect(app(EdgeRedirects::class)->find($connection, ['monoven.com']))->toBe([]);

    Http::assertNotSent(fn(Request $request): bool => str_starts_with($request->url(), 'https://monoven.com'));
});

test("a domain Cloudflare answers only over IPv6 is checked through Cloudflare's IPv6 address", function () {
    edgeRedirectsCloudflare(['monoven.com' => 'https://cloudflare.vc/'], ['monoven.com' => [['type' => 28, 'data' => '2606:4700:3033::ac43:b831']]]);
    $connection = edgeRedirectsConnection(['monoven.com' => '']);

    expect(app(EdgeRedirects::class)->find($connection, ['monoven.com']))->toBe([['host' => 'monoven.com', 'to' => 'cloudflare.vc']]);
});
