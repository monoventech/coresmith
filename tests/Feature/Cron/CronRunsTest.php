<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Jobs\CollectServerMetrics;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Models\Server;
use App\Services\Cron\CronRuns;
use App\Services\Panel\CronJobs;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSSHTransport;

function cronRunsApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'cron_jobs' => [
            ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
            ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        ],
        'current_release' => '20261019-120000',
        ...$attributes,
    ]);
}

function cronRunsId(string $schedule, string $command): string
{
    return CronJobs::id(['schedule' => $schedule, 'command' => $command]);
}

test('one command, run as root, prints the last five runs of each job, what is running, and the server clock', function () {
    $records = sys_get_temp_dir() . '/coresmith-records-' . bin2hex(random_bytes(4));
    $id = str_repeat('a', 16);
    mkdir("{$records}/shop", 0700, true);
    file_put_contents("{$records}/shop/{$id}.runs", implode("\n", array_map(fn(int $start): string => "{$start} 2 0", range(1, 7))) . "\n");
    file_put_contents("{$records}/shop/{$id}.999999999.running", "1794700000\n");

    $read = new Process(['sh', '-c', str_replace('/var/lib/coresmith/cron', $records, CronRuns::command())]);
    $read->run();

    $stale = file_exists("{$records}/shop/{$id}.999999999.running");
    unlink("{$records}/shop/{$id}.runs");
    rmdir("{$records}/shop");
    rmdir($records);

    expect(CronRuns::command())->toStartWith("for record in '/var/lib/coresmith/cron'/*/*.runs; do ")
        ->and($read->getExitCode())->toBe(0, $read->getErrorOutput())
        ->and($read->getOutput())->toMatch('/\A== shop\/' . $id . '\.runs\n3 2 0\n4 2 0\n5 2 0\n6 2 0\n7 2 0\n== clock \d{10,} [A-Za-z0-9_+\/-]+\n\z/')
        ->and($stale)->toBeFalse();
});

test('a server with no records prints only its clock', function () {
    $read = new Process(['sh', '-c', str_replace('/var/lib/coresmith/cron', sys_get_temp_dir() . '/coresmith-none-' . bin2hex(random_bytes(4)), CronRuns::command())]);
    $read->run();

    expect($read->getExitCode())->toBe(0)->and($read->getOutput())->toMatch('/\A== clock \d{10,} \S+\n\z/');
});

test('runs, running jobs and the clock are read by app and job, and anything else is skipped', function () {
    $id = str_repeat('b', 16);

    expect(CronRuns::parse("== shop/{$id}.runs\n1794700000 3 0\nnot a run\n1794700060 12 1\n== ../etc/passwd.runs\n1 1 1\n== shop/short.runs\n2 2 2\n== shop/{$id}.4242.running\n1794700120\n== shop/{$id}.4343.running\n1794700100\n== clock 1794700150 Europe/London\n"))
        ->toBe([
            'runs' => ['shop' => [$id => [
                ['started' => 1794700000, 'seconds' => 3, 'exit' => 0],
                ['started' => 1794700060, 'seconds' => 12, 'exit' => 1],
            ]]],
            'running' => ['shop' => [$id => 1794700120]],
            'clock' => ['now' => 1794700150, 'timezone' => 'Europe/London'],
        ]);
});

test('a timezone the server names that PHP does not know is read as UTC', function (string $zone, string $read) {
    expect(CronRuns::parse("== clock 1794700150 {$zone}\n")['clock'])->toBe(['now' => 1794700150, 'timezone' => $read]);
})->with([
    'a known zone' => ['America/New_York', 'America/New_York'],
    'an old alias' => ['Etc/UTC', 'Etc/UTC'],
    'a made-up zone' => ['Mars/Olympus', 'UTC'],
]);

test('each job keeps when Coresmith first saw it, its latest run and whether it is running, and a job that is gone is dropped', function () {
    $application = cronRunsApp();
    $scheduler = cronRunsId('* * * * *', 'php artisan schedule:run');
    $backup = cronRunsId('0 3 * * *', 'php artisan backup:run');

    app(CronRuns::class)->record($application, [
        $scheduler => [['started' => 1794700000, 'seconds' => 1, 'exit' => 0], ['started' => 1794700060, 'seconds' => 2, 'exit' => 0]],
        str_repeat('c', 16) => [['started' => 1794700000, 'seconds' => 1, 'exit' => 1]],
    ], [$backup => 1794700050], 1794700100);

    app(CronRuns::class)->record($application->fresh(), [], [], 1794700400);

    expect($application->fresh()->cron_runs)->toEqual([
        $scheduler => ['seen' => 1794700100, 'last' => ['started' => 1794700060, 'seconds' => 2, 'exit' => 0], 'running' => null],
        $backup => ['seen' => 1794700100, 'last' => null, 'running' => null],
    ]);
});

test('an app with no cron jobs is not asked about', function () {
    $transport = new FakeSSHTransport;
    $application = cronRunsApp(['cron_jobs' => null]);

    app(CronRuns::class)->read((new SSHConnection($application->server, $transport))->connect(), collect([$application]));

    expect($transport->commands())->toBe([]);
});

test('the minute read records each app job\'s last run, and a read that fails is reported without stopping the collection', function (bool $fails) {
    Exceptions::fake();
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $application = cronRunsApp(['server_id' => $server->id, 'team_id' => $server->team_id, 'cron_written_at' => now()]);
    $backup = cronRunsId('0 3 * * *', 'php artisan backup:run');
    $transport = (new FakeSSHTransport)
        ->respondTo('top -bn1', "cpu=12.5\nmemory=40\ndisk=40\nload=0.42,0.51,0.60\nnet=1000,2000\nuptime=93630\n")
        ->respondTo('for record in', "== shop/{$backup}.runs\n1794700000 9 3\n", $fails ? 1 : 0);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new CollectServerMetrics($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $runs = $application->fresh()->cron_runs;

    expect($fails ? $runs : $runs[$backup]['last'])->toEqual($fails ? null : ['started' => 1794700000, 'seconds' => 9, 'exit' => 3])
        ->and($server->fresh()->last_connected_at)->not->toBeNull();

    $fails ? Exceptions::assertReportedCount(1) : Exceptions::assertNothingReported();
})->with([
    'a read that works' => [false],
    'a read that fails' => [true],
]);

test('an app whose jobs were written before runs were recorded gets them written again, once', function (?string $written, bool $rewritten) {
    Queue::fake();
    $application = cronRunsApp(['cron_written_at' => $written === null ? null : now()->subDay()]);
    $transport = (new FakeSSHTransport)->respondTo('for record in', "== clock 1794700150 UTC\n");

    app(CronRuns::class)->read((new SSHConnection($application->server, $transport))->connect(), collect([$application]));

    $rewritten ? Queue::assertPushed(SyncApplicationCron::class, 1) : Queue::assertNotPushed(SyncApplicationCron::class);
})->with([
    'written before this release' => [null, true],
    'written since' => ['yesterday', false],
]);

test('a server in another zone has its cron times named in that zone', function () {
    $application = cronRunsApp();
    $application->server->forceFill(['meta' => ['timezone' => 'Europe/London']])->save();

    $card = app(CronJobs::class)->describe($application->fresh());

    expect($card['zone'])->toBe('London time')
        ->and($card['jobs'][1]['sentence'])->toBe('Daily at 3:00 AM London time');
});
