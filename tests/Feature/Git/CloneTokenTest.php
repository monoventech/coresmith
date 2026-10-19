<?php

declare(strict_types=1);

use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\Git\CloneToken;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

function cloneTokenFill(string $host): ProcessResult
{
    $tokens = app(CloneToken::class);
    $directory = realpath(sys_get_temp_dir()) . '/coresmith-clone-token-' . Str::lower(Str::random(10));

    File::ensureDirectoryExists($directory, 0700);

    try {
        File::put($directory . '/my-app.git-token', $tokens->content('ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl'));
        File::put($directory . '/gitconfig', "[credential]\n\thelper = \"!f() { echo username=someone-else; echo password=from-another-helper; }; f\"\n");

        $command = str_replace(
            '/etc/coresmith/deploy-keys/my-app.git-token',
            $directory . '/my-app.git-token',
            $tokens->wrap(Application::factory()->make(['slug' => 'my-app']), 'credential fill'),
        );

        return Process::path($directory)
            ->env([
                'GIT_CONFIG_GLOBAL' => $directory . '/gitconfig',
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_ASKPASS' => false,
                'SSH_ASKPASS' => false,
                'GIT_DIR' => false,
            ])
            ->input("protocol=https\nhost={$host}\n\n")
            ->run(['bash', '-c', $command]);
    } finally {
        File::deleteDirectory($directory);
    }
}

test('the credential helper hands git the token for github.com and keeps other helpers out of it', function () {
    $result = cloneTokenFill('github.com');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain("username=x-access-token\npassword=ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl\n")
        ->and($result->output())->not->toContain('from-another-helper');
});

test('the credential helper answers no other host', function (string $host) {
    $result = cloneTokenFill($host);

    expect($result->successful())->toBeFalse()
        ->and($result->output())->not->toContain('password=')
        ->and($result->errorOutput())->toContain('terminal prompts disabled');
})->with([
    'another host' => 'example.com',
    'a look-alike host' => 'github.com.example.com',
    'a github subdomain' => 'gist.github.com',
]);

test('an app whose slug could climb out of the key directory gets no token file', function (string $slug) {
    expect(fn() => app(CloneToken::class)->path(Application::factory()->make(['slug' => $slug])))->toThrow(UnsafeNameException::class);
})->with(['a parent directory' => '../root', 'a nested path' => 'my-app/../../etc', 'nothing' => '']);
