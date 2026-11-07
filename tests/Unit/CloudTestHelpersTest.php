<?php

declare(strict_types=1);

test('every helper the cloud tests declare carries the cloud prefix', function () {
    $unprefixed = [];

    foreach (glob(dirname(__DIR__) . '/Feature/Cloud/*.php') as $file) {
        preg_match_all('/^function (\w+)\(/m', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $name) {
            if (!str_starts_with($name, 'cloud')) {
                $unprefixed[] = basename($file) . ": {$name}";
            }
        }
    }

    expect($unprefixed)->toBe([]);
});

test('the cloud helpers in other test files carry the cloud prefix too', function (string $file, string $name) {
    expect((string) file_get_contents(dirname(__DIR__) . "/Feature/{$file}"))->not->toMatch("/^function {$name}\\(/m");
})->with([
    ['Panel/FleetSnapshotTest.php', 'fleetCreating'],
    ['Panel/FleetSnapshotTest.php', 'fleetDestroying'],
    ['Panel/FleetSnapshotTest.php', 'fleetResizing'],
    ['Http/DeploymentScreenTest.php', 'screenBusy'],
    ['Http/WebhookControllerTest.php', 'webhookBusy'],
    ['Api/TokenAuthTest.php', 'apiBusy'],
]);
