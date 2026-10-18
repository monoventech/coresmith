<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

function cspNonceFrom(string $header): string
{
    preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $header, $matches);

    return $matches[1] ?? '';
}

test('every page carries a content security policy whose nonce the route list uses', function () {
    $response = $this->get('/login');
    $header = (string) $response->headers->get('Content-Security-Policy');
    $nonce = cspNonceFrom($header);

    expect($nonce)->not->toBe('')
        ->and($header)->toContain("default-src 'self'")
        ->and($header)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($header)->toContain("img-src 'self' data:")
        ->and($header)->not->toContain('unsafe-eval')
        ->and($response->getContent())->toContain('<script type="text/javascript" nonce="' . $nonce . '">');
});

test('each response gets a fresh nonce', function () {
    $first = cspNonceFrom((string) $this->get('/login')->headers->get('Content-Security-Policy'));
    app()->forgetInstance(Illuminate\Foundation\Vite::class);
    Vite::clearResolvedInstances();
    $this->withoutVite();
    $second = cspNonceFrom((string) $this->get('/login')->headers->get('Content-Security-Policy'));

    expect($first)->not->toBe('')
        ->and($second)->not->toBe('')
        ->and($second)->not->toBe($first);
});

test('the websocket endpoint the frontend was built for is allowed', function () {
    config(['coresmith.websocket_origin' => 'wss://panel.example.com:443']);

    expect((string) $this->get('/login')->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'self' wss://panel.example.com:443");
});

test('without a websocket endpoint only the panel itself may be contacted', function () {
    config(['coresmith.websocket_origin' => null]);

    expect((string) $this->get('/login')->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'self';");
});

test('the policy can be switched off', function () {
    config(['coresmith.content_security_policy' => false]);

    expect($this->get('/login')->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('a running vite dev server is left alone so hot reloading keeps working', function () {
    $this->withVite();
    $hot = tempnam(sys_get_temp_dir(), 'coresmith-hot-');
    file_put_contents($hot, 'http://localhost:5173');
    Vite::useHotFile($hot);

    try {
        expect($this->get('/login')->headers->has('Content-Security-Policy'))->toBeFalse();
    } finally {
        @unlink($hot);
    }
});

test('the horizon dashboard is left to its own scripts', function () {
    expect($this->get('/' . trim((string) config('horizon.path'), '/'))->headers->has('Content-Security-Policy'))->toBeFalse();
});
