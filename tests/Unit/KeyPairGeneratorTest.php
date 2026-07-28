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

    expect(PublicKeyLoader::load($pair->privateKey)->getPublicKey()->toString('OpenSSH'))
        ->toBe($pair->publicKey);
});

test('each generated pair is unique', function () {
    $generator = new KeyPairGenerator;

    expect($generator->generate()->privateKey)->not->toBe($generator->generate()->privateKey);
});
