<?php

declare(strict_types=1);

use App\Services\Deployment\NetworkFailure;

test('a host that answers 502, 503 or 504 on every try is named as the trouble', function (string $output, string $host, string $status) {
    expect(NetworkFailure::reason($output, 2))->toBe("The trouble is at {$host}, which answered {$status} both times. Deploy again in a few minutes.");
})->with([
    'laravel-vite-plugin fonts' => ['Error: laravel-vite-plugin: Failed to fetch "https://fonts.bunny.net/css2?family=Instrument+Sans:wght@400;500;600&display=swap&subset=latin": 504 Gateway Timeout', 'fonts.bunny.net', '504 Gateway Timeout'],
    'composer' => ['The "https://repo.packagist.org/p2/laravel/framework.json" file could not be downloaded (HTTP/2 503 )', 'repo.packagist.org', '503 Service Unavailable'],
    'npm' => ["npm error code E502\nnpm error 502 Bad Gateway - GET https://registry.npmjs.org/left-pad", 'registry.npmjs.org', '502 Bad Gateway'],
    'nginx wording' => ['Failed to fetch https://cdn.example.com/fonts.css: 504 Gateway Time-out', 'cdn.example.com', '504 Gateway Timeout'],
]);

test('a single try is not counted', function () {
    expect(NetworkFailure::reason('Error: laravel-vite-plugin: Failed to fetch "https://fonts.bunny.net/css2?family=Inter": 503 Service Unavailable', 1))
        ->toBe('The trouble is at fonts.bunny.net, which answered 503 Service Unavailable. Deploy again in a few minutes.');
});

test('a host the server cannot connect to is named with the firewall advice', function (string $output, string $host) {
    expect(NetworkFailure::reason($output, 2))->toBe("The server tried twice and couldn't reach {$host}. Deploy again in a few minutes, and if it still fails, check the server's firewall allows outbound connections.");
})->with([
    'composer timeout' => ['curl error 28 while downloading https://repo.packagist.org/p2/laravel/framework.json: Operation timed out after 300000 milliseconds with 0 bytes received', 'repo.packagist.org'],
    'npm timeout' => ["npm error code ETIMEDOUT\nnpm error network request to https://registry.npmjs.org/vite failed, reason: connect ETIMEDOUT 104.16.2.35:443\nnpm error network This is a problem related to network connectivity.", 'registry.npmjs.org'],
    'rubygems timeout' => ['Gem::RemoteFetcher::FetchError: Net::OpenTimeout: Failed to open TCP connection to rubygems.org:443 (execution expired) (https://rubygems.org/gems/rails-8.0.1.gem)', 'rubygems.org'],
    'git through composer' => ["Failed to execute git clone --mirror -- 'https://github.com/laravel/framework.git'\nfatal: unable to access 'https://github.com/laravel/framework.git/': Failed to connect to github.com port 443: Connection timed out", 'github.com'],
]);

test('a host the server cannot look up is named with the DNS advice', function (string $output, string $host) {
    expect(NetworkFailure::reason($output, 2))->toBe("The server tried twice and couldn't look up {$host}. Deploy again in a few minutes, and if it still fails, check the server's DNS settings.");
})->with([
    'composer' => ['curl error 6 while downloading https://repo.packagist.org/packages.json: Could not resolve host: repo.packagist.org', 'repo.packagist.org'],
    'npm not found' => ["npm error code ENOTFOUND\nnpm error network request to https://registry.npmjs.org/vite failed, reason: getaddrinfo ENOTFOUND registry.npmjs.org", 'registry.npmjs.org'],
    'npm lookup again' => ['npm error network request to https://registry.npmjs.org/vite failed, reason: getaddrinfo EAI_AGAIN registry.npmjs.org', 'registry.npmjs.org'],
    'bundler' => ['Could not fetch specs from https://rubygems.org/ due to underlying error <SocketError: Failed to open TCP connection to rubygems.org:443 (getaddrinfo: Temporary failure in name resolution) (https://rubygems.org/specs.4.8.gz)>', 'rubygems.org'],
]);

test('one failed connection says the server could not reach the host', function () {
    expect(NetworkFailure::reason('curl error 28 while downloading https://repo.packagist.org/packages.json: Operation timed out', 1))
        ->toBe("The server couldn't reach repo.packagist.org. Deploy again in a few minutes, and if it still fails, check the server's firewall allows outbound connections.");
});

test('the last failing line names the host, so a download that recovered earlier is not blamed', function () {
    $output = "curl error 28 while downloading https://repo.packagist.org/p2/laravel/framework.json: Operation timed out\nRetrying download\nError: laravel-vite-plugin: Failed to fetch \"https://fonts.bunny.net/css2?family=Inter\": 504 Gateway Timeout";

    expect(NetworkFailure::reason($output, 2))->toStartWith('The trouble is at fonts.bunny.net,');
});

test('a network warning the tool recovered from is not blamed for a later error', function () {
    $output = "npm warn retry will retry, error on last attempt: Error: request to https://registry.npmjs.org/vite failed, reason: connect ETIMEDOUT 104.16.2.35:443\nadded 120 packages in 9s\nerror during build: Rollup failed to resolve import \"lodash/each\"";

    expect(NetworkFailure::reason($output, 1))->toBeNull();
});

test('a failure it cannot pin on an outside host gets no network advice', function (string $output) {
    expect(NetworkFailure::reason($output, 2))->toBeNull();
})->with([
    'the app calling itself' => ['TypeError: fetch failed: connect ECONNREFUSED 127.0.0.1:3000 while fetching http://localhost:3000/api/posts'],
    'loopback by address' => ['Error: connect ETIMEDOUT while fetching http://127.0.0.1:8000/sitemap.xml'],
    'loopback over IPv6' => ['Error: connect ECONNREFUSED ::1:3000 while fetching http://[::1]:3000/api'],
    'a missing package' => ['The "https://repo.packagist.org/p2/acme/missing.json" file could not be downloaded (HTTP/2 404 )'],
    'a server error that is not about reaching it' => ['Failed to fetch https://api.example.com/build-data: 500 Internal Server Error'],
    'no address on the failing line' => ["Downloading https://registry.npmjs.org/vite\nnpm error code ETIMEDOUT"],
    'a database refusing migrations' => ['SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: 10.0.0.5, Port: 3306)'],
    'a certificate problem' => ['curl error 60 while downloading https://repo.packagist.org/packages.json: SSL certificate problem: unable to get local issuer certificate'],
    'nothing about the network' => ['error during build: Rollup failed to resolve import "lodash/each"'],
]);
