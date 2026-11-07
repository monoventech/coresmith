<?php

declare(strict_types=1);

use App\Services\Updates\InvalidFeed;
use App\Services\Updates\ReleaseFeed;
use App\Services\Updates\Version;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['coresmith.release_feed.enabled' => true, 'coresmith.version' => '1.0.2']);

    $this->changelogDirectory = sys_get_temp_dir() . '/coresmith-feed-' . bin2hex(random_bytes(6));
    mkdir($this->changelogDirectory, 0700);
    $this->changelog = $this->changelogDirectory . '/CHANGELOG.md';

    app()->bind(ReleaseFeed::class, fn(): ReleaseFeed => new ReleaseFeed($this->changelog));
});

afterEach(function () {
    @unlink($this->changelog);
    @rmdir($this->changelogDirectory);
});

test('the feed is off unless this deployment serves it', function () {
    config(['coresmith.release_feed.enabled' => false]);

    $this->getJson(route('release-feed'))->assertNotFound();
});

test('the feed advertises the running version in the contract shape', function () {
    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertExactJson([
            'schema' => 1, 'latest' => '1.0.2', 'minimum' => null, 'withdrawn' => [],
            'changelog_url' => 'https://github.com/monoventech/coresmith/releases/tag/v1.0.2',
            'summary' => null, 'released_at' => null, 'channel' => 'stable',
        ])
        ->assertHeader('Cache-Control', 'max-age=300, public');
});

test('withdrawn versions and the minimum come from the environment', function () {
    config(['coresmith.release_feed.withdrawn' => ' 1.0.1, 1.0.0 ,', 'coresmith.release_feed.minimum' => '1.0.0']);

    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertJsonPath('withdrawn', ['1.0.1', '1.0.0'])
        ->assertJsonPath('minimum', '1.0.0');
});

test('an empty minimum in the environment is no minimum', function (string $minimum) {
    config(['coresmith.release_feed.minimum' => $minimum]);

    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertJsonPath('minimum', null);
})->with(['empty' => [''], 'blank' => ['  ']]);

test('a misconfigured feed answers 503 and is reported rather than served', function (array $settings) {
    Exceptions::fake();
    config($settings);

    $this->getJson(route('release-feed'))
        ->assertStatus(503)
        ->assertExactJson(['message' => 'The release feed is misconfigured.']);

    Exceptions::assertReported(InvalidFeed::class);
})->with([
    'a withdrawn entry that is not a version' => [['coresmith.release_feed.withdrawn' => '1.0.1,soon']],
    'a minimum that is not a version' => [['coresmith.release_feed.minimum' => 'old']],
    'a pre-release on the stable channel' => [['coresmith.version' => '1.1.0-beta.1']],
    'an unknown channel' => [['coresmith.release_feed.channel' => 'nightly']],
]);

test('a pre-release deployment says why it cannot serve the stable feed', function () {
    config(['coresmith.version' => '1.1.0-beta.1']);

    expect(fn() => app(ReleaseFeed::class)->document())
        ->toThrow(InvalidFeed::class, 'This deployment runs a pre-release, so it cannot serve the stable feed.');
});

test('a beta deployment can serve a pre-release', function () {
    config(['coresmith.version' => '1.1.0-beta.1', 'coresmith.release_feed.channel' => 'beta']);

    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertJsonPath('latest', '1.1.0-beta.1')
        ->assertJsonPath('channel', 'beta');
});

test('the summary and date come from the running release\'s changelog entry', function () {
    file_put_contents($this->changelog, "# Changelog\n\n## v1.0.2 (2026-10-18)\n\n### Fixed\n- Deploy logs keep their colours\n\n## v1.0.1 (2026-10-01)\n\n- Older\n");

    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertJsonPath('summary', "### Fixed\n- Deploy logs keep their colours")
        ->assertJsonPath('released_at', '2026-10-18');
});

test('a changelog without the running release has no summary or date', function () {
    file_put_contents($this->changelog, "# Changelog\n\n## v1.0.1 (2026-10-01)\n\n- Older\n");

    $this->getJson(route('release-feed'))
        ->assertOk()
        ->assertJsonPath('summary', null)
        ->assertJsonPath('released_at', null);
});

test('a changelog entry runs to the end of the file when it is the last one', function () {
    expect(ReleaseFeed::changelogEntry("## v1.0.2 (2026-10-18)\r\n\r\n- Only entry\r\n", Version::parse('1.0.2')))
        ->toBe(['summary' => '- Only entry', 'released_at' => '2026-10-18'])
        ->and(ReleaseFeed::changelogEntry("## v1.0.20 (2026-10-18)\n\n- Not this one\n", Version::parse('1.0.2')))->toBeNull();
});

test('the feed answers on an instance nobody has set up yet', function () {
    config(['fortify.features' => []]);

    $this->getJson(route('release-feed'))->assertOk()->assertJsonPath('latest', '1.0.2');
});

test('the feed is rate limited', function () {
    expect(Route::getRoutes()->getByName('release-feed')->gatherMiddleware())->toContain('throttle:60,1,release-feed');
});
