<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\RuntimeUnavailable;
use App\Models\Application;
use App\Services\Runtime\JsTooling;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use App\Services\Runtime\StackResolver;

test('the stack of a laravel app puts together versions, framework and tooling', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);
    $facts = new RepositoryFacts([
        'composer.json' => '{"require":{"php":"^8.4","laravel/framework":"^13.0"}}',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ], ['bun.lock'], ['laravel/framework' => '13.2.0']);

    $stack = app(StackResolver::class)->resolve($application, $facts, fn(string $runtime): array => $runtime === 'php' ? ['8.4.20'] : []);

    expect($stack->versions())->toBe(['php' => '8.4.20', 'bun' => '1.4.2'])
        ->and(array_map(fn(ResolvedRuntime $runtime): string => $runtime->runtime, $stack->missing()))->toBe(['bun'])
        ->and($stack->framework)->toBe(['name' => 'laravel', 'major' => 13])
        ->and($stack->tooling?->manager)->toBe('bun');
});

test('a version chosen for the app applies wherever the repository allows it, and is refused where it does not', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);
    $application->runtime_pins = ['php' => '8.3'];

    $bare = app(StackResolver::class)->resolve($application, new RepositoryFacts, fn(): array => []);
    $allowed = app(StackResolver::class)->resolve($application, new RepositoryFacts(['composer.json' => '{"require":{"php":"^8.2"}}']), fn(): array => []);

    expect($bare->runtimes['php']->version)->toBe('8.3.33')
        ->and($bare->runtimes['php']->source)->toBe(ResolvedRuntime::FROM_PIN)
        ->and($allowed->runtimes['php']->version)->toBe('8.3.33')
        ->and($allowed->runtimes['php']->source)->toBe(ResolvedRuntime::FROM_PIN)
        ->and(fn() => app(StackResolver::class)->resolve($application, new RepositoryFacts(['composer.json' => '{"require":{"php":"^8.4"}}']), fn(): array => []))
        ->toThrow(RuntimeUnavailable::class, 'This app is set to PHP 8.3, but require.php in composer.json asks for ^8.4.');
});

test('the stack holds php where composer.lock stops', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);
    $lock = (string) json_encode(['packages' => [['name' => 'nette/utils', 'version' => 'v4.0.5', 'require' => ['php' => '8.0 - 8.4']]]]);

    $stack = app(StackResolver::class)->resolve($application, new RepositoryFacts(['composer.json' => '{"require":{"php":"^8.2"}}', 'composer.lock' => $lock]), fn(): array => ['8.5.10']);

    expect($stack->runtimes['php']->version)->toBe('8.4.25')
        ->and($stack->runtimes['php']->heldBy)->toBe(['nette/utils 4.0.5'])
        ->and($stack->runtimes['php']->newest)->toBe('8.5.10');
});

test('notes from reading and from resolving are both carried on the stack', function () {
    $application = new Application(['type' => ApplicationType::Js, 'js_runtime' => 'node', 'framework' => '']);
    $facts = new RepositoryFacts(['.nvmrc' => "lts/zeppelin\n", 'package.json' => '{"engines":{"node":"soon"}}']);

    expect(app(StackResolver::class)->resolve($application, $facts, fn(): array => [])->notes)->toBe([
        'Ignored .nvmrc, because lts/zeppelin is not a Node.js version Coresmith knows.',
        'engines.node in package.json says soon, which is not a version Coresmith understands, so it was ignored.',
    ]);
});

test('a stack survives being stored and read back', function () {
    $stack = new Stack(['php' => new ResolvedRuntime('php', '8.4.20', 'composer.json', '^8.4', true)], ['name' => 'laravel', 'major' => 13], new JsTooling('bun', true, true, false), [], ['sidekiq' => false, 'solid_queue' => true]);
    $restored = Stack::fromArray($stack->toArray());

    expect($restored->toArray())->toBe($stack->toArray())
        ->and($restored->gems)->toBe(['sidekiq' => false, 'solid_queue' => true])
        ->and($restored->missing())->toBe([])
        ->and(Stack::fromArray(null)->runtimes)->toBe([]);
});

test('a ruby app records whether its lockfile locks sidekiq and solid queue', function (array $locked, array $gems) {
    $application = new Application(['type' => ApplicationType::Ruby, 'framework' => 'rails']);
    $facts = new RepositoryFacts(['.ruby-version' => "3.4.10\n"], [], $locked);

    $stack = app(StackResolver::class)->resolve($application, $facts, fn(): array => ['3.4.10']);

    expect($stack->gems)->toBe($gems)
        ->and($stack->toArray()['gems'])->toBe($gems)
        ->and($stack->applicationAttributes()['stack']['gems'])->toBe($gems);
})->with([
    'solid queue' => [['rails' => '8.0.2', 'solid_queue' => '1.1.5'], ['sidekiq' => false, 'solid_queue' => true]],
    'sidekiq' => [['rails' => '7.2.1', 'sidekiq' => '7.3.9'], ['sidekiq' => true, 'solid_queue' => false]],
    'neither' => [['rails' => '8.0.2'], ['sidekiq' => false, 'solid_queue' => false]],
]);

test('an app that is not ruby records no gems', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);

    expect(app(StackResolver::class)->resolve($application, new RepositoryFacts([], [], ['sidekiq' => '7.3.9']), fn(): array => [])->gems)->toBe([]);
});

test('a stack stored before gems were recorded reads back with none, so no worker is assumed', function () {
    expect(Stack::fromArray(['runtimes' => [], 'framework' => ['name' => 'rails', 'major' => 8], 'tooling' => null])->gems)->toBe([])
        ->and(Stack::fromArray(['gems' => ['sidekiq' => 'yes', 'solid_queue' => true, 3 => true]])->gems)->toBe(['solid_queue' => true]);
});

test('an application takes a stack as its live versions', function () {
    $stack = new Stack([
        'php' => new ResolvedRuntime('php', '8.4.20', 'composer.json', '^8.4', true),
        'bun' => new ResolvedRuntime('bun', '1.4.2', ResolvedRuntime::FROM_DEFAULT, null, true),
    ]);

    expect($stack->applicationAttributes())->toBe([
        'php_version' => '8.4.20',
        'node_version' => null,
        'bun_version' => '1.4.2',
        'deno_version' => null,
        'ruby_version' => null,
        'stack' => $stack->toArray(),
    ]);
});
