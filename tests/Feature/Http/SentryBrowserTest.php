<?php

declare(strict_types=1);

use App\Support\Sentry\BrowserSentry;
use Illuminate\Support\Env;
use Illuminate\Testing\TestResponse;

function browserSentryMeta(string $html): ?array
{
    if (preg_match('/<meta name="coresmith-sentry" content="([^"]*)">/', $html, $matches) !== 1) {
        return null;
    }

    $settings = json_decode(html_entity_decode($matches[1]), true);

    return is_array($settings) ? $settings : null;
}

function browserSentryConnect(TestResponse $response): string
{
    preg_match('/connect-src ([^;]*)/', (string) $response->headers->get('Content-Security-Policy'), $matches);

    return $matches[1] ?? '';
}

function browserSentryConfigured(?string $dsn, float|int|string|null $rate = 0.0): void
{
    config([
        'coresmith.websocket_origin' => null,
        'coresmith.sentry.browser_dsn' => $dsn,
        'coresmith.sentry.browser_traces_sample_rate' => $rate,
    ]);
}

function browserSentryConfigWith(array $environment): array
{
    $repository = Env::getRepository();

    foreach ($environment as $name => $value) {
        $repository->set($name, $value);
    }

    try {
        return require config_path('coresmith.php');
    } finally {
        foreach (array_keys($environment) as $name) {
            $repository->clear($name);
        }
    }
}

test('without a browser dsn the page carries no settings and the policy stays tight', function () {
    browserSentryConfigured(null);

    $response = $this->get('/login');

    expect($response->getContent())->not->toContain('coresmith-sentry')
        ->and(browserSentryMeta($response->getContent()))->toBeNull()
        ->and(browserSentryConnect($response))->toBe("'self'")
        ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('sentry');
});

test('a browser dsn adds its settings and its origin', function () {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507', 0.2);

    $response = $this->get('/login');

    expect(browserSentryMeta($response->getContent()))->toBe([
        'dsn' => 'https://abc123@o450.ingest.sentry.io/4507',
        'environment' => 'testing',
        'release' => config('coresmith.version'),
        'tracesSampleRate' => 0.2,
    ])->and(browserSentryConnect($response))->toBe("'self' https://o450.ingest.sentry.io");
});

test('an address that is not a sentry dsn is ignored', function (?string $dsn) {
    browserSentryConfigured($dsn, 0.2);

    $response = $this->get('/login');

    expect(browserSentryMeta($response->getContent()))->toBeNull()
        ->and($response->getContent())->not->toContain('coresmith-sentry')
        ->and(browserSentryConnect($response))->toBe("'self'")
        ->and(BrowserSentry::settings())->toBeNull()
        ->and(BrowserSentry::origin())->toBeNull();
})->with([
    'nothing' => [null],
    'blank' => [''],
    'not a url' => ['not a url'],
    'a scheme that is not http' => ['ftp://abc@host.example/1'],
    'no public key' => ['https://host.example/1'],
    'no project' => ['https://abc@host.example/'],
    'a project that is not a number' => ['https://abc@host.example/12a'],
    'a path in front of the project' => ['https://abc@host.example/sentry/12'],
    'a secret key' => ['https://abc:secret@host.example/12'],
    'a query' => ['https://abc@host.example/12?debug=1'],
    'a fragment' => ['https://abc@host.example/12#top'],
    'a new line after the project' => ["https://abc@host.example/12\n"],
    'a host that adds a directive' => ['https://abc@host.example; script-src *;/12'],
    'a host with a quote' => ["https://abc@host.example'/12"],
]);

test('a dsn on a port keeps the port in the policy', function () {
    browserSentryConfigured('http://abc123@sentry.example.com:9000/12');

    $response = $this->get('/login');

    expect(browserSentryMeta($response->getContent())['dsn'])->toBe('http://abc123@sentry.example.com:9000/12')
        ->and(browserSentryConnect($response))->toBe("'self' http://sentry.example.com:9000")
        ->and(BrowserSentry::origin())->toBe('http://sentry.example.com:9000');
});

test('the ingest origin joins the websocket endpoint in the policy', function () {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507');
    config(['coresmith.websocket_origin' => 'wss://panel.example.com:443']);

    expect(browserSentryConnect($this->get('/login')))
        ->toBe("'self' wss://panel.example.com:443 https://o450.ingest.sentry.io");
});

test('the settings reach the page when the policy is off', function () {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507');
    config(['coresmith.content_security_policy' => false]);

    $response = $this->get('/login');

    expect(browserSentryMeta($response->getContent()))->not->toBeNull()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('the policy and the settings read the same address', function () {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507');

    expect(BrowserSentry::origin())->toBe('https://o450.ingest.sentry.io')
        ->and(BrowserSentry::settings()['dsn'])->toBe('https://abc123@o450.ingest.sentry.io/4507');

    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/');

    expect(BrowserSentry::origin())->toBeNull()
        ->and(BrowserSentry::settings())->toBeNull();
});

test('the sample rate reaches the page as a number between 0 and 1', function (float|int|string|null $configured, float $sent) {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507', $configured);

    $settings = browserSentryMeta($this->get('/login')->getContent());

    expect($settings['tracesSampleRate'])->toEqual($sent);
})->with([
    'unset' => [null, 0.0],
    'blank' => ['', 0.0],
    'zero' => [0, 0.0],
    'text' => ['often', 0.0],
    'a fraction' => ['0.35', 0.35],
    'one' => [1, 1.0],
    'above one' => [5, 1.0],
    'below zero' => [-0.5, 0.0],
]);

test('the environment and release follow sentry\'s own settings', function () {
    browserSentryConfigured('https://abc123@o450.ingest.sentry.io/4507');
    config(['sentry.environment' => 'staging', 'sentry.release' => '9.9.9']);

    expect(BrowserSentry::settings())->toMatchArray(['environment' => 'staging', 'release' => '9.9.9']);

    config(['sentry.environment' => '']);

    expect(BrowserSentry::settings()['environment'])->toBe('testing');
});

test('the example environment names the browser keys', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^SENTRY_BROWSER_DSN=$/m')
        ->toMatch('/^SENTRY_BROWSER_TRACES_SAMPLE_RATE=0$/m');
});

test('the browser keys default to off and a blank value reads as unset', function () {
    $off = ['browser_dsn' => null, 'browser_traces_sample_rate' => 0.0];

    expect(browserSentryConfigWith([])['sentry'])->toBe($off)
        ->and(browserSentryConfigWith(['SENTRY_BROWSER_DSN' => '', 'SENTRY_BROWSER_TRACES_SAMPLE_RATE' => ''])['sentry'])->toBe($off)
        ->and(browserSentryConfigWith(['SENTRY_BROWSER_DSN' => 'https://abc@host.example/1', 'SENTRY_BROWSER_TRACES_SAMPLE_RATE' => '0.25'])['sentry'])
        ->toBe(['browser_dsn' => 'https://abc@host.example/1', 'browser_traces_sample_rate' => 0.25]);
});
