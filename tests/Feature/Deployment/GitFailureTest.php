<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\SourceProvider;
use App\Services\Deployment\GitFailure;

function gitFailureApp(string $url = 'https://github.com/monoven/atlas.git'): Application
{
    return Application::factory()->make(['repository_url' => $url, 'repository_branch' => 'release/2.0']);
}

function gitFailureLinkedApp(array $connection = []): Application
{
    $provider = SourceProvider::factory()->githubApp()->create(array_merge(['name' => 'monoventech', 'installation_id' => '51842'], $connection));

    return Application::factory()->make([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_branch' => 'main',
        'source_provider_id' => $provider->id,
        'repository_id' => '70000001',
    ]);
}

test('git output becomes a reason people can act on', function (string $output, string $reason, bool $retryable) {
    $failure = GitFailure::from($output, gitFailureApp());

    expect($failure->reason)->toContain($reason)
        ->and($failure->retryable)->toBe($retryable);
})->with([
    'missing repository' => ["remote: Repository not found.\nfatal: repository 'https://github.com/monoven/atlas.git/' not found", 'Repository not found at https://github.com/monoven/atlas.git.', false],
    'private without access' => ['git@github.com: Permission denied (publickey).', 'Coresmith could not read the repository on github.com.', false],
    'missing branch' => ['fatal: not a valid object name: release/2.0', 'Branch release/2.0 is not in the repository.', false],
    'dns' => ["fatal: unable to access 'https://github.com/monoven/atlas.git/': Could not resolve host: github.com", 'The server could not look up github.com.', true],
    'timeout' => ["fatal: unable to access 'https://github.com/monoven/atlas.git/': Failed to connect to github.com port 443 after 130001 ms: Connection timed out", 'The server could not reach github.com.', true],
    'dropped transfer' => ['error: RPC failed; curl 56 GnuTLS recv error (-9)', 'The server could not reach github.com.', true],
    'unknown' => ['fatal: something new', 'Its full output is in the log.', false],
]);

test('a caller with no log to point at says what an unrecognised failure means', function () {
    $unrecognised = 'git ls-remote failed with exit code 1. A deploy shows the full output.';

    expect(GitFailure::from('fatal: something new', gitFailureApp(), unrecognised: $unrecognised)->reason)->toBe($unrecognised)
        ->and(GitFailure::from('fatal: something new', gitFailureApp(), unrecognised: $unrecognised)->retryable)->toBeFalse()
        ->and(GitFailure::from('git@github.com: Permission denied (publickey).', gitFailureApp(), unrecognised: $unrecognised)->reason)->toStartWith('Coresmith could not read the repository on github.com.')
        ->and(GitFailure::from('fatal: something new', gitFailureApp())->reason)->toBe('Git failed with an error Coresmith does not recognise. Its full output is in the log.');
});

test('the host is named for ssh style urls too', function () {
    expect(GitFailure::from('Connection timed out', gitFailureApp('git@gitlab.example.com:ops/atlas.git'))->reason)->toContain('gitlab.example.com');
});

test("a linked app's authentication failure points at the github app", function (string $output) {
    expect(GitFailure::from($output, gitFailureLinkedApp())->reason)
        ->toBe('GitHub did not let Coresmith read monoventech/atlas. Check the repository is still selected for the Coresmith app on GitHub, then deploy again.');
})->with([
    'no username' => ["fatal: could not read Username for 'https://github.com': terminal prompts disabled"],
    'a refused token' => ["remote: Invalid username or token.\nfatal: Authentication failed for 'https://github.com/monoventech/atlas.git/'"],
]);

test('advice from a refused token wins', function () {
    $failure = GitFailure::from("fatal: could not read Username for 'https://github.com': terminal prompts disabled", gitFailureLinkedApp(), 'Coresmith could not get a clone token from GitHub. Try again in a minute.');

    expect($failure->reason)->toBe('Coresmith could not get a clone token from GitHub. Try again in a minute.')
        ->and($failure->retryable)->toBeFalse();
});

test('advice from a refused token only answers for an authentication failure', function () {
    $failure = GitFailure::from('Failed to connect to github.com port 443: Connection timed out', gitFailureLinkedApp(), 'Coresmith could not get a clone token from GitHub. Try again in a minute.');

    expect($failure->reason)->toBe('The server could not reach github.com. Check its firewall allows outbound HTTPS and SSH, then deploy again.')
        ->and($failure->retryable)->toBeTrue();
});

test("a linked app whose connection is gone names the connection, not the repository's settings", function () {
    expect(GitFailure::from('fatal: could not read Username', gitFailureLinkedApp(['deleted_at' => now()]))->reason)
        ->toBe("This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy.");
});

test('a linked app whose installation is suspended says to unsuspend it', function () {
    expect(GitFailure::from('fatal: could not read Username', gitFailureLinkedApp(['suspended_at' => now()]))->reason)
        ->toBe("Coresmith's GitHub app is suspended on monoventech. Unsuspend it on GitHub, then deploy.");
});

test('a token in the repository url never reaches the reason', function () {
    $failure = GitFailure::from('remote: Repository not found.', gitFailureApp('https://ghp_abcdefghijklmnopqrstuvwxyz123456@github.com/monoven/atlas.git'));

    expect($failure->reason)->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz123456')
        ->and($failure->reason)->toContain('Repository not found at https://github.com/monoven/atlas.git.');
});
