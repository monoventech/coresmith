<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Services\Git\Repository;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\KeyPairGenerator;

test('a repository url is reduced to its owner and name', function (string $url, ?string $expected) {
    expect(Repository::slug($url))->toBe($expected);
})->with([
    'https with .git' => ['https://github.com/monoven/coresmith.git', 'monoven/coresmith'],
    'https without .git' => ['https://github.com/monoven/coresmith', 'monoven/coresmith'],
    'https with a trailing slash' => ['https://github.com/monoven/coresmith/', 'monoven/coresmith'],
    'ssh' => ['git@github.com:monoven/coresmith.git', 'monoven/coresmith'],
    'gitlab subgroups' => ['https://gitlab.com/monoven/platform/coresmith.git', 'monoven/platform/coresmith'],
    'self hosted over ssh' => ['git@git.example.com:ops/deploy-tools.git', 'ops/deploy-tools'],
    'surrounding whitespace' => ['  https://github.com/monoven/coresmith  ', 'monoven/coresmith'],
    'dots in the name' => ['https://github.com/monoven/coresmith.io.git', 'monoven/coresmith.io'],
]);

test('a url that is not a recognisable repository has no slug', function (string $url) {
    expect(Repository::slug($url))->toBeNull();
})->with([
    'empty' => '',
    'plain http' => 'http://github.com/monoven/coresmith',
    'owner only' => 'https://github.com/monoven',
    'not a url' => 'monoven/coresmith',
    'query string' => 'https://github.com/monoven/coresmith?tab=readme',
    'spaces in the path' => 'https://github.com/monoven/core smith',
]);

test('a public key fingerprint matches what ssh-keygen prints', function () {
    $publicKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAID1mMDNsL9LCfDTYhnoHpWmxAPDE+kQmRCK3NHkfGfMp deploy@coresmith';

    expect(HostKeyFingerprint::fromPublicKey($publicKey))->toBe('SHA256:Xl/jKyuvKrFTpjRJe7mfKlKvBCANaqbn86pG1sf2G4k');
});

test('a public key without a comment or with extra whitespace has the same fingerprint', function () {
    expect(HostKeyFingerprint::fromPublicKey("  ssh-ed25519\tAAAAC3NzaC1lZDI1NTE5AAAAID1mMDNsL9LCfDTYhnoHpWmxAPDE+kQmRCK3NHkfGfMp\n"))
        ->toBe('SHA256:Xl/jKyuvKrFTpjRJe7mfKlKvBCANaqbn86pG1sf2G4k');
});

test('a generated key pair fingerprints its own public key', function () {
    $pair = (new KeyPairGenerator)->generate();
    $blob = base64_decode(preg_split('/\s+/', $pair->publicKey)[1], true);

    expect(HostKeyFingerprint::fromPublicKey($pair->publicKey))->toBe('SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='));
});

test('something that is not a public key has no fingerprint', function (string $publicKey) {
    expect(HostKeyFingerprint::fromPublicKey($publicKey))->toBeNull();
})->with([
    'empty' => '',
    'type only' => 'ssh-ed25519',
    'not base64' => 'ssh-ed25519 !!!not-base64!!!',
]);

test('team roles have the labels the panel shows', function (TeamRole $role, string $label) {
    expect($role->label())->toBe($label);
})->with([
    [TeamRole::Owner, 'Owner'],
    [TeamRole::Admin, 'Admin'],
    [TeamRole::Member, 'Deployer'],
]);
