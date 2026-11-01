<?php

declare(strict_types=1);

use App\Models\Team;
use App\Services\Git\GitHub\ManifestBuilder;
use Illuminate\Support\Facades\Http;

function manifestBuilder(): ManifestBuilder
{
    return app(ManifestBuilder::class);
}

function manifestTeamNamed(string $name): Team
{
    return new Team(['name' => $name]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.url' => 'https://panel.example.com']);
});

test('the manifest asks for the minimum and points github at this panel', function () {
    expect(manifestBuilder()->base())->toBe([
        'url' => 'https://panel.example.com/',
        'redirect_url' => 'https://panel.example.com/settings/git/github/registered',
        'callback_urls' => ['https://panel.example.com/settings/source-providers/github_app/callback'],
        'description' => 'Deploys from panel.example.com with Coresmith. Reads repository contents and posts commit statuses.',
        'public' => false,
        'default_permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
        'request_oauth_on_install' => true,
        'setup_on_update' => false,
    ])->and(manifestBuilder()->base())->not->toHaveKey('hook_attributes')
        ->and(manifestBuilder()->base())->not->toHaveKey('default_events');
});

test('the webhook part names this app\'s own address', function () {
    expect(manifestBuilder()->webhook('01jaj4v0z8x6v3c2c2e8n1q6m7'))->toBe([
        'hook_attributes' => ['url' => 'https://panel.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7', 'active' => true],
        'default_events' => ['push'],
    ]);
});

test('every address in the manifest is the panel\'s own, however the panel url is written', function (string $appUrl) {
    config(['app.url' => $appUrl]);

    expect(manifestBuilder()->redirectUrl())->toBe('https://panel.example.com/settings/git/github/registered')
        ->and(manifestBuilder()->callbackUrl())->toBe('https://panel.example.com/settings/source-providers/github_app/callback')
        ->and(manifestBuilder()->webhookUrl('01jaj4v0z8x6v3c2c2e8n1q6m7'))->toBe('https://panel.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7')
        ->and(manifestBuilder()->base()['url'])->toBe('https://panel.example.com/');
})->with(['https://panel.example.com', 'https://panel.example.com/']);

test('the manifest only ever asks for contents, metadata and commit statuses, and the push event', function () {
    expect(ManifestBuilder::PERMISSIONS)->toBe(['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'])
        ->and(ManifestBuilder::EVENTS)->toBe(['push']);
});

test('the form goes to the personal or the organization page with the state', function () {
    expect(manifestBuilder()->personalAction('s'))->toBe('https://github.com/settings/apps/new?state=s')
        ->and(manifestBuilder()->organizationAction('s'))->toBe('https://github.com/organizations/{organization}/settings/apps/new?state=s');
});

test('a state made of letters and digits goes into the form address as it is', function () {
    $state = 'aB3dE5gH7jK9mN1pQ3sT5vW7yZ9bD1fH3jL5nP7r';

    expect(manifestBuilder()->personalAction($state))->toBe("https://github.com/settings/apps/new?state={$state}")
        ->and(manifestBuilder()->organizationAction($state))->toBe("https://github.com/organizations/{organization}/settings/apps/new?state={$state}");
});

test('the default name is the team\'s, shortened to what github allows', function (string $team, string $expected) {
    expect(manifestBuilder()->defaultName(manifestTeamNamed($team)))->toBe($expected);
})->with([
    'a plain name' => ['Acme Labs', 'Coresmith Acme Labs'],
    'accents, symbols and doubled spaces' => ['Ünïcode & Co!', 'Coresmith Unicode Co'],
    'a name that is only symbols' => ['!!!', 'Coresmith'],
    'a name with outer spaces' => ['  Acme  ', 'Coresmith Acme'],
    'hyphens are kept' => ['Acme-Labs', 'Coresmith Acme-Labs'],
    'a name that is all accents' => ['Émile Zola', 'Coresmith Emile Zola'],
]);

test('a long team name is cut so the app name stays within what github allows', function (string $team) {
    $name = manifestBuilder()->defaultName(manifestTeamNamed($team));

    expect(strlen($name))->toBeLessThanOrEqual(34)
        ->and($name)->toStartWith('Coresmith')
        ->and($name)->toMatch(ManifestBuilder::NAME_PATTERN)
        ->and($name)->toBe(trim($name))
        ->and($name)->not->toEndWith('-');
})->with([
    'sixty characters of words' => [str_repeat('Acme ', 12)],
    'sixty characters in one word' => [str_repeat('a', 60)],
    'a cut that lands on a space' => [str_repeat('x', 23) . ' ' . str_repeat('y', 30)],
    'a cut that lands on a hyphen' => [str_repeat('x', 23) . '-' . str_repeat('y', 30)],
]);

test('a cut that lands on a space or a hyphen leaves no loose end', function () {
    expect(manifestBuilder()->defaultName(manifestTeamNamed(str_repeat('x', 23) . ' ' . str_repeat('y', 30))))->toBe('Coresmith ' . str_repeat('x', 23))
        ->and(manifestBuilder()->defaultName(manifestTeamNamed(str_repeat('x', 23) . '-' . str_repeat('y', 30))))->toBe('Coresmith ' . str_repeat('x', 23));
});

test('a long name is cut at the limit rather than dropped', function () {
    expect(manifestBuilder()->defaultName(manifestTeamNamed(str_repeat('a', 60))))->toBe('Coresmith ' . str_repeat('a', 24));
});

test('organization names github accepts', function (string $login, bool $valid) {
    expect(preg_match(ManifestBuilder::ORGANIZATION_PATTERN, $login) === 1)->toBe($valid);
})->with([
    'a word' => ['acme', true],
    'a hyphenated word' => ['acme-labs', true],
    'a letter and a digit' => ['a1', true],
    'one character' => ['a', true],
    'upper case' => ['AcmeLabs', true],
    'thirty nine characters' => [str_repeat('a', 39), true],
    'a space' => ['acme labs', false],
    'a leading hyphen' => ['-acme', false],
    'a trailing hyphen' => ['acme-', false],
    'a doubled hyphen' => ['acme--labs', false],
    'a slash' => ['a/b', false],
    'forty characters' => [str_repeat('a', 40), false],
    'nothing' => ['', false],
    'an underscore' => ['acme_labs', false],
    'a trailing newline' => ["acme\n", false],
    'a trailing carriage return and newline' => ["acme\r\n", false],
    'a leading newline' => ["\nacme", false],
    'a newline inside' => ["acme\nlabs", false],
]);

test('app names github accepts', function (string $name, bool $valid) {
    expect(preg_match(ManifestBuilder::NAME_PATTERN, $name) === 1)->toBe($valid);
})->with([
    'a plain name' => ['Coresmith Acme Labs', true],
    'one character' => ['a', true],
    'a hyphen inside' => ['Coresmith Acme-Labs', true],
    'thirty four characters' => [str_repeat('a', 34), true],
    'thirty five characters' => [str_repeat('a', 35), false],
    'a leading space' => [' Acme', false],
    'a leading hyphen' => ['-Acme', false],
    'an underscore' => ['Acme_Labs', false],
    'a symbol' => ['Acme & Co', false],
    'nothing' => ['', false],
    'a trailing newline' => ["Coresmith Acme\n", false],
    'a trailing carriage return and newline' => ["Coresmith Acme\r\n", false],
    'a leading newline' => ["\nAcme", false],
    'a newline inside' => ["Coresmith\nAcme", false],
]);
