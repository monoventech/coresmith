<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentPhases;

function phasesDeployment(DeploymentStatus $status, array $lines): Deployment
{
    return new Deployment(['status' => $status, 'log' => implode("\n", $lines)]);
}

function phasesByKey(Deployment $deployment): array
{
    return collect((new DeploymentPhases)->states($deployment))->keyBy('key')->all();
}

function phasesStates(Deployment $deployment): array
{
    return collect((new DeploymentPhases)->states($deployment))->pluck('state', 'key')->all();
}

function phasesDurations(Deployment $deployment): array
{
    return collect((new DeploymentPhases)->states($deployment))->pluck('duration', 'key')->all();
}

test('every phase is listed in deploy order with its label', function () {
    $phases = (new DeploymentPhases)->states(phasesDeployment(DeploymentStatus::Pending, []));

    expect(array_column($phases, 'key'))->toBe(['clone', 'deps', 'build', 'migrate', 'health', 'swap'])
        ->and(array_column($phases, 'label'))->toBe([
            'Clone release',
            'Install dependencies',
            'Build assets',
            'Run migrations',
            'Health check',
            'Symlink swap',
        ]);
});

test('a successful deployment marks every phase done and times each one up to the next marker', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[12:00:00] release 20260913120000 · branch main · triggered by push',
        '[12:00:00] clone: fetching over https',
        '[12:00:04] deps: installing dependencies',
        '$ composer install --no-dev --optimize-autoloader --no-interaction',
        '[12:01:10] build: building the release',
        '[12:01:15] migrate: running migrations against release 20260913120000',
        '[12:01:17] health: checking the release before it goes live',
        'health: index.php present',
        '[12:01:18] swap: current → releases/20260913120000',
        '[12:01:20] deployed. previous release kept for instant rollback.',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'done',
        'migrate' => 'done',
        'health' => 'done',
        'swap' => 'done',
    ])->and(phasesDurations($deployment))->toBe([
        'clone' => '4s',
        'deps' => '1m 06s',
        'build' => '5s',
        'migrate' => '2s',
        'health' => '1s',
        'swap' => '2s',
    ]);
});

test('a running deployment shows the latest started phase as running and the rest as pending', function () {
    $deployment = phasesDeployment(DeploymentStatus::Running, [
        '[09:15:00] clone: fetching over https',
        '[09:15:03] deps: installing dependencies',
        '[09:15:40] build: building the release',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'running',
        'migrate' => 'pending',
        'health' => 'pending',
        'swap' => 'pending',
    ])->and(phasesDurations($deployment))->toBe([
        'clone' => '3s',
        'deps' => '37s',
        'build' => '…',
        'migrate' => '',
        'health' => '',
        'swap' => '',
    ]);
});

test('a failed deployment fails the phase it was in, skips the rest and times the failure to the end marker', function () {
    $deployment = phasesDeployment(DeploymentStatus::Failed, [
        '[10:00:00] clone: fetching over https',
        '[10:00:02] deps: installing dependencies',
        '[10:00:30] migrate: running migrations against release 20260913100000',
        'SQLSTATE[42S01]: duplicate column',
        '[10:00:39] deploy failed. the current release is untouched.',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'skipped',
        'migrate' => 'failed',
        'health' => 'skipped',
        'swap' => 'skipped',
    ])->and(phasesDurations($deployment))->toBe([
        'clone' => '2s',
        'deps' => '28s',
        'build' => '—',
        'migrate' => '9s',
        'health' => '—',
        'swap' => '—',
    ]);
});

test('a failure after the symlink swap fails the swap phase and still times it', function () {
    $deployment = phasesDeployment(DeploymentStatus::Failed, [
        '[10:00:00] clone: fetching over https',
        '[10:00:05] health: checking the release before it goes live',
        '[10:00:06] swap: current → releases/20260913100000',
        '[10:00:09] deploy failed after the symlink swap. releases/20260913100000 is live, check its processes.',
    ]);

    expect(phasesByKey($deployment)['swap'])->toMatchArray(['state' => 'failed', 'duration' => '3s']);
});

test('a phase that a later phase overtook is skipped rather than left pending', function () {
    $deployment = phasesDeployment(DeploymentStatus::Running, [
        '[08:00:00] clone: fetching over https',
        '[08:00:07] health: checking the release before it goes live',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'skipped',
        'build' => 'skipped',
        'migrate' => 'skipped',
        'health' => 'running',
        'swap' => 'pending',
    ]);
});

test('a phase is timed to the next phase that actually started, not the next one in order', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[08:00:00] clone: fetching over https',
        '[08:00:12] health: checking the release before it goes live',
        '[08:00:13] swap: current → releases/20260913080000',
        '[08:00:15] deployed. previous release kept for instant rollback.',
    ]);

    expect(phasesDurations($deployment))->toBe([
        'clone' => '12s',
        'deps' => '—',
        'build' => '—',
        'migrate' => '—',
        'health' => '1s',
        'swap' => '2s',
    ]);
});

test('durations that cross midnight wrap instead of going negative', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[23:59:50] clone: fetching over https',
        '[00:00:10] deps: installing dependencies',
        '[00:01:25] swap: current → releases/20260914000000',
        '[00:01:27] deployed. previous release kept for instant rollback.',
    ]);

    expect(phasesDurations($deployment))->toMatchArray([
        'clone' => '20s',
        'deps' => '1m 15s',
        'swap' => '2s',
    ]);
});

test('the end marker itself wraps past midnight', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[23:59:58] swap: current → releases/20260913235958',
        '[00:00:01] deployed. previous release kept for instant rollback.',
    ]);

    expect(phasesByKey($deployment)['swap']['duration'])->toBe('3s');
});

test('a finished phase with no later marker and no end marker has no duration', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[08:00:00] clone: fetching over https',
        '[08:00:04] swap: current → releases/20260913080000',
    ]);

    expect(phasesByKey($deployment)['swap'])->toMatchArray(['state' => 'done', 'duration' => '']);
});

test('only the first marker for a phase counts', function () {
    $deployment = phasesDeployment(DeploymentStatus::Succeeded, [
        '[08:00:00] clone: fetching over https',
        '[08:00:30] clone: fetching again',
        '[08:00:40] deps: installing dependencies',
        '[08:00:41] deployed. previous release kept for instant rollback.',
    ]);

    expect(phasesByKey($deployment)['clone']['duration'])->toBe('40s');
});

test('markers must start the line and name a known phase', function () {
    $deployment = phasesDeployment(DeploymentStatus::Running, [
        'output mentioning [08:00:00] deps: inside a line',
        '[08:00:00] release 20260913080000 · branch main · triggered by push',
        '[08:00:01] warmup: not a phase',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'running',
        'deps' => 'pending',
        'build' => 'pending',
        'migrate' => 'pending',
        'health' => 'pending',
        'swap' => 'pending',
    ]);
});

test('a log with no markers follows the deployment status', function (DeploymentStatus $status, array $states, array $durations) {
    $deployment = phasesDeployment($status, ['Another deployment is already in progress.']);

    expect(array_values(phasesStates($deployment)))->toBe($states)
        ->and(array_values(phasesDurations($deployment)))->toBe($durations);
})->with([
    'queued' => [DeploymentStatus::Pending, array_fill(0, 6, 'pending'), array_fill(0, 6, '')],
    'just started' => [DeploymentStatus::Running, ['running', 'pending', 'pending', 'pending', 'pending', 'pending'], ['…', '', '', '', '', '']],
    'succeeded' => [DeploymentStatus::Succeeded, array_fill(0, 6, 'done'), array_fill(0, 6, '')],
    'failed' => [DeploymentStatus::Failed, array_fill(0, 6, 'skipped'), array_fill(0, 6, '—')],
    'cancelled' => [DeploymentStatus::Cancelled, array_fill(0, 6, 'skipped'), array_fill(0, 6, '—')],
]);

test('a cancelled deployment that had started fails the phase it stopped in', function () {
    $deployment = phasesDeployment(DeploymentStatus::Cancelled, [
        '[08:00:00] clone: fetching over https',
        '[08:00:05] deps: installing dependencies',
    ]);

    expect(phasesStates($deployment))->toMatchArray(['clone' => 'done', 'deps' => 'failed', 'build' => 'skipped']);
});

test('progress names the phase that is running', function () {
    $deployment = phasesDeployment(DeploymentStatus::Running, [
        '[08:00:00] clone: fetching over https',
        '[08:00:05] deps: installing dependencies',
        '[08:00:50] migrate: running migrations against release 20260913080000',
    ]);

    expect((new DeploymentPhases)->progress($deployment))->toBe('running migrations');
});

test('progress for a deployment that has not written a marker yet is cloning', function () {
    expect((new DeploymentPhases)->progress(phasesDeployment(DeploymentStatus::Running, [])))->toBe('cloning the release');
});

test('progress is empty once nothing is running', function (DeploymentStatus $status) {
    $deployment = phasesDeployment($status, [
        '[08:00:00] clone: fetching over https',
        '[08:00:05] swap: current → releases/20260913080000',
    ]);

    expect((new DeploymentPhases)->progress($deployment))->toBeNull();
})->with([DeploymentStatus::Succeeded, DeploymentStatus::Failed, DeploymentStatus::Cancelled]);

test('the process watch after the swap is not a phase of its own', function () {
    $lines = [
        '[14:02:12] clone: fetching over https',
        '[14:02:20] deps: installing dependencies',
        '[14:02:50] build: building the release',
        '[14:03:05] health: checking the release before it goes live',
        '[14:03:06] swap: current → releases/20260914-140206',
    ];

    $without = phasesStates(phasesDeployment(DeploymentStatus::Succeeded, [...$lines, '[14:03:07] deployed. previous release kept for instant rollback.']));
    $with = phasesStates(phasesDeployment(DeploymentStatus::Succeeded, [...$lines, '[14:03:07] watch: keeping an eye on the processes for 60 seconds', 'watch: every process stayed up', '[14:04:07] deployed. previous release kept for instant rollback.']));

    expect(array_keys($with))->toBe(['clone', 'deps', 'build', 'migrate', 'health', 'swap'])
        ->and($with)->toBe($without);
});

test('a deploy whose processes did not start does not blame the symlink swap, because the swap finished', function () {
    $deployment = phasesDeployment(DeploymentStatus::Failed, [
        '[15:08:29] clone: fetching over https',
        '[15:08:38] deps: installing dependencies',
        '[15:09:10] build: building the release',
        '[15:09:24] health: checking the release before it goes live',
        '[15:09:25] swap: current → releases/20260916-150829',
        '[15:09:26] watch: starting coresmith-app-shop-web',
        '[15:09:29] deploy failed after the symlink swap. releases/20260916-150829 is live, check its processes.',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'done',
        'migrate' => 'skipped',
        'health' => 'done',
        'swap' => 'done',
    ]);
});

test('a deploy the crash loop watch failed does not blame the symlink swap, because the swap finished', function () {
    $deployment = phasesDeployment(DeploymentStatus::Failed, [
        '[15:08:29] clone: fetching over https',
        '[15:08:38] deps: installing dependencies',
        '[15:09:10] build: building the release',
        '[15:09:24] health: checking the release before it goes live',
        '[15:09:25] swap: current → releases/20260916-150829',
        '[15:09:29] watch: keeping an eye on the processes for 60 seconds',
        '[15:10:00] deploy failed after the symlink swap. releases/20260916-150829 is live, check its processes.',
    ]);

    expect(phasesStates($deployment))->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'done',
        'migrate' => 'skipped',
        'health' => 'done',
        'swap' => 'done',
    ]);
});

test('a deploy whose symlink swap itself failed still blames the swap', function () {
    $deployment = phasesDeployment(DeploymentStatus::Failed, [
        '[15:08:29] clone: fetching over https',
        '[15:09:24] health: checking the release before it goes live',
        '[15:09:25] swap: current → releases/20260916-150829',
        '[15:09:26] deploy failed. The symlink could not be moved.',
    ]);

    expect(phasesStates($deployment)['swap'])->toBe('failed');
});

test('a deploy still inside the process watch says so, rather than falling back to starting', function () {
    $deployment = phasesDeployment(DeploymentStatus::Running, [
        '[15:09:24] health: checking the release before it goes live',
        '[15:09:25] swap: current → releases/20260916-150829',
        '[15:09:29] watch: keeping an eye on the processes for 60 seconds',
    ]);

    expect((new DeploymentPhases)->progress($deployment))->toBe('watching the processes')
        ->and(phasesStates($deployment)['swap'])->toBe('done');
});
