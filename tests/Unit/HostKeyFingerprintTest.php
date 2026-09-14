<?php

declare(strict_types=1);

use App\Services\SSH\HostKeyFingerprint;

test('formats an openssh style sha256 fingerprint', function () {
    $fingerprint = HostKeyFingerprint::fromHostKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5');

    expect($fingerprint)->toStartWith('SHA256:')->and($fingerprint)->not->toEndWith('=');
});

test('is stable for the same key', function () {
    expect(HostKeyFingerprint::fromHostKey('key-material'))
        ->toBe(HostKeyFingerprint::fromHostKey('key-material'));
});

test('differs for different keys', function () {
    expect(HostKeyFingerprint::fromHostKey('key-a'))->not->toBe(HostKeyFingerprint::fromHostKey('key-b'));
});

test('matches the openssh reference digest', function () {
    expect(HostKeyFingerprint::fromHostKey('abc'))
        ->toBe('SHA256:' . rtrim(base64_encode(hash('sha256', 'abc', true)), '='));
});
