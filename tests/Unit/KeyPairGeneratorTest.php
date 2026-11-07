<?php

declare(strict_types=1);

use App\Services\SSH\KeyPairGenerator;
use phpseclib3\Crypt\PublicKeyLoader;

test('generates an openssh ed25519 private key', function () {
    expect((new KeyPairGenerator)->generate()->privateKey)
        ->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----');
});

test('generates a matching ed25519 public key', function () {
    expect((new KeyPairGenerator)->generate()->publicKey)->toStartWith('ssh-ed25519 ');
});

test('the private key loads and yields the same public key', function () {
    $pair = (new KeyPairGenerator)->generate();
    $material = fn(string $key): string => implode(' ', array_slice(explode(' ', trim($key)), 0, 2));

    expect($material(PublicKeyLoader::load($pair->privateKey)->getPublicKey()->toString('OpenSSH')))
        ->toBe($material($pair->publicKey));
});

test('keys say they belong to coresmith, so they are recognisable in authorized_keys', function () {
    expect((new KeyPairGenerator)->generate()->publicKey)->toEndWith(' coresmith')
        ->and((new KeyPairGenerator)->generate('coresmith@web-1')->publicKey)->toEndWith(' coresmith@web-1');
});

test('each generated pair is unique', function () {
    $generator = new KeyPairGenerator;

    expect($generator->generate()->privateKey)->not->toBe($generator->generate()->privateKey);
});
