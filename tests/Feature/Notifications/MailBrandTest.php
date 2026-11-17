<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerReachableNotification;

test('every email opens with the Coresmith lockup, linked to Coresmith', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    $html = (string) (new ServerReachableNotification($server, '5 minutes'))->toMail(User::factory()->create())->render();

    expect($html)->toContain('src="http://localhost/coresmith-lockup-on-light.png"')
        ->toContain('alt="Coresmith"')
        ->toContain('width="160"')
        ->toMatch('~<a href="http://localhost"[^>]*>\s*<img src="http://localhost/coresmith-lockup-on-light.png"~');
});

test('the lockup the emails show is the brand kit file, in public', function () {
    $file = public_path('coresmith-lockup-on-light.png');

    expect(is_file($file))->toBeTrue()
        ->and(getimagesize($file))->toMatchArray([0 => 1200, 1 => 193, 'mime' => 'image/png']);
});
