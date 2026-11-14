<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\RepositoryFacts;

function foldersFacts(array $files = [], array $markers = []): RepositoryFacts
{
    return new RepositoryFacts($files, markers: $markers, complete: true, folder: 'apps/site');
}

test('what a language finds or misses in a folder names the folder, not the repository\'s root', function (Closure $said, string $text) {
    expect($said())->toBe($text);
})->with([
    'no Gemfile' => [fn(): string => ApplicationType::Ruby->language()->checks(new Application(['type' => ApplicationType::Ruby]), 'rails', foldersFacts(), null)[0]->reason, "There is no Gemfile in apps/site, so this isn't a Ruby app."],
    'no bin/rails' => [fn(): string => ApplicationType::Ruby->language()->checks(new Application(['type' => ApplicationType::Ruby]), 'rails', foldersFacts(['Gemfile' => "gem 'rails'", 'Gemfile.lock' => "GEM\n  specs:\n    puma (6.6.0)\n"]), null)[0]->reason, "There is no bin/rails, so this isn't a Rails app in apps/site."],
    'a Rack app' => [fn(): string => ApplicationType::Ruby->language()->detect(foldersFacts(['Gemfile' => "gem 'rack'"], ['config.ru']))['sources']['framework'], 'config.ru in apps/site'],
    'no composer.json' => [fn(): string => ApplicationType::Php->language()->checks(new Application(['type' => ApplicationType::Php]), 'laravel', foldersFacts(), null)[0]->reason, "There is no composer.json, so this isn't a Laravel app in apps/site."],
    'no artisan' => [fn(): string => ApplicationType::Php->language()->checks(new Application(['type' => ApplicationType::Php]), 'laravel', foldersFacts(['composer.json' => '{}']), null)[0]->reason, "There is no artisan file in apps/site, so this isn't a Laravel app."],
    'index.php' => [fn(): string => ApplicationType::Php->language()->detect(foldersFacts([], ['index.php']))['sources']['web_directory'], 'index.php is in apps/site'],
    'index.html' => [fn(): string => ApplicationType::Static->language()->detect(foldersFacts([], ['index.html']))['sources']['web_directory'], 'index.html is in apps/site'],
]);
