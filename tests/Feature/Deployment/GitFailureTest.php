<?php

declare(strict_types=1);

use App\Models\Application;
use App\Services\Deployment\GitFailure;

function gitFailureApp(string $url = 'https://github.com/monoven/atlas.git'): Application
{
    return Application::factory()->make(['repository_url' => $url, 'repository_branch' => 'release/2.0']);
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

test('the host is named for ssh style urls too', function () {
    expect(GitFailure::from('Connection timed out', gitFailureApp('git@gitlab.example.com:ops/atlas.git'))->reason)->toContain('gitlab.example.com');
});

test('a token in the repository url never reaches the reason', function () {
    $failure = GitFailure::from('remote: Repository not found.', gitFailureApp('https://ghp_abcdefghijklmnopqrstuvwxyz123456@github.com/monoven/atlas.git'));

    expect($failure->reason)->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz123456')
        ->and($failure->reason)->toContain('Repository not found at https://github.com/monoven/atlas.git.');
});
