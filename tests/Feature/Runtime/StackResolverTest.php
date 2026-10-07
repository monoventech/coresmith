<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
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

test('a version chosen when the app was added applies only where the repository names none', function () {
    $application = new Application(['type' => ApplicationType::Php, 'framework' => 'laravel']);
    $application->runtime_pins = ['php' => '8.3'];

    $bare = app(StackResolver::class)->resolve($application, new RepositoryFacts, fn(): array => []);
    $declared = app(StackResolver::class)->resolve($application, new RepositoryFacts(['composer.json' => '{"require":{"php":"^8.4"}}']), fn(): array => []);

    expect($bare->runtimes['php']->version)->toBe('8.3.33')
        ->and($bare->runtimes['php']->source)->toBe(ResolvedRuntime::FROM_PIN)
        ->and($declared->runtimes['php']->source)->toBe('composer.json');
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
    $stack = new Stack(['php' => new ResolvedRuntime('php', '8.4.20', 'composer.json', '^8.4', true)], ['name' => 'laravel', 'major' => 13], new JsTooling('bun', true, true, false));
    $restored = Stack::fromArray($stack->toArray());

    expect($restored->toArray())->toBe($stack->toArray())
        ->and($restored->missing())->toBe([])
        ->and(Stack::fromArray(null)->runtimes)->toBe([]);
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
