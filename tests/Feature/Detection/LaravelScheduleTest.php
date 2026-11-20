<?php

declare(strict_types=1);

use App\Services\Detection\LaravelSchedule;
use App\Services\Runtime\RepositoryFacts;

test('a Laravel app that schedules work is spotted from its console routes, kernel or bootstrap', function (string $file, string $code) {
    expect(LaravelSchedule::detected(new RepositoryFacts(files: [$file => $code])))->toBeTrue();
})->with([
    'console routes' => ['routes/console.php', "<?php\n\nSchedule::command('reports:send')->dailyAt('06:00');\n"],
    'a scheduled closure' => ['routes/console.php', "<?php\n\nSchedule::call(fn () => cache()->flush())->hourly();\n"],
    'the old console kernel' => ['app/Console/Kernel.php', "<?php\n\nprotected function schedule(Schedule \$schedule): void\n{\n    \$schedule->command('backup:run')->daily();\n}\n"],
    'bootstrap' => ['bootstrap/app.php', "<?php\n\nreturn Application::configure()\n    ->withSchedule(function (Schedule \$schedule) {\n        \$schedule->job(new Prune)->daily();\n    })->create();\n"],
]);

test("the starter's inspire command and commented-out schedules don't count", function (string $file, string $code) {
    expect(LaravelSchedule::detected(new RepositoryFacts(files: [$file => $code])))->toBeFalse();
})->with([
    'inspire' => ['routes/console.php', "<?php\n\nArtisan::command('inspire', function () {\n    \$this->comment(Inspiring::quote());\n})->purpose('Display an inspiring quote');\n"],
    'a commented line' => ['app/Console/Kernel.php', "<?php\n\nprotected function schedule(Schedule \$schedule): void\n{\n    // \$schedule->command('inspire')->hourly();\n}\n"],
    'a comment block' => ['routes/console.php', "<?php\n\n/*\nSchedule::command('reports:send')->daily();\n*/\n"],
]);

test('without a repository read there is nothing to spot', function () {
    expect(LaravelSchedule::detected(null))->toBeFalse()
        ->and(LaravelSchedule::detected(new RepositoryFacts))->toBeFalse();
});
