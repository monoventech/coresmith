<?php

declare(strict_types=1);

use App\Models\Application;
use App\Services\Runtime\FrameworkVersion;
use App\Services\Runtime\RepositoryFacts;

test('the framework version comes from the lockfile first, then the manifest', function (string $framework, array $files, array $locked, ?array $expected) {
    $application = new Application(['framework' => $framework]);

    expect(app(FrameworkVersion::class)->detect($application, new RepositoryFacts($files, [], $locked)))->toBe($expected);
})->with([
    'laravel locked' => ['laravel', ['composer.json' => '{"require":{"laravel/framework":"^12.0"}}'], ['laravel/framework' => '13.2.0'], ['name' => 'laravel', 'major' => 13]],
    'laravel declared' => ['laravel', ['composer.json' => '{"require":{"laravel/framework":"^13.0"}}'], [], ['name' => 'laravel', 'major' => 13]],
    'symfony locked' => ['symfony', [], ['symfony/framework-bundle' => '7.3.4'], ['name' => 'symfony', 'major' => 7]],
    'rails locked' => ['rails', [], ['rails' => '8.0.2'], ['name' => 'rails', 'major' => 8]],
    'rails declared' => ['rails', ['Gemfile' => "gem \"rails\", \"~> 8.1.0\"\n"], [], ['name' => 'rails', 'major' => 8]],
    'sveltekit' => ['sveltekit', ['package.json' => '{"devDependencies":{"@sveltejs/kit":"^2.8.0"}}'], [], ['name' => 'sveltekit', 'major' => 2]],
    'next' => ['nextjs', ['package.json' => '{"dependencies":{"next":"15.5.2"}}'], [], ['name' => 'nextjs', 'major' => 15]],
    'no framework' => ['', [], [], null],
    'nothing to read' => ['laravel', [], [], null],
]);
