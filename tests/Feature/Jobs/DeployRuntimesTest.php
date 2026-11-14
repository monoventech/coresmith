<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\RuntimeUnavailable;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\InstallRuntimes;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\NativeGemTools;
use App\Services\Runtime\NativePythonTools;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

const STACK_RELEASE = '20260925120000';

function stackApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/monoven/shop.git',
        'run_migrations' => false,
        'base_port' => 8000,
        ...$attributes,
    ]);
}

function stackTransport(array $files = [], array $lockfiles = [], array $installed = []): FakeSSHTransport
{
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for($files, $lockfiles));

    foreach ($installed as $runtime => $versions) {
        $transport->respondTo("ls -1 '/opt/asdf-data/installs/{$runtime}'", implode("\n", $versions) . "\n");
    }

    return $transport;
}

function stackDeploy(Deployment $deployment, FakeSSHTransport $transport, ?string $owner = null): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, STACK_RELEASE, $owner))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function stackPausedDeployment(Application $application): Deployment
{
    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'stale_after' => now()->addMinute(),
        'release' => STACK_RELEASE,
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'framework' => null, 'tooling' => null],
    ]);
}

test('the repository is read right after the clone and its versions are recorded on the deployment', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}'], [], ['php' => ['8.4.20']]);

    stackDeploy($deployment, $transport);

    $commands = $transport->commands();
    $archive = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, ' archive ')));
    $probe = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, RepositoryProbe::DIRECTORY_VARIABLE)));
    $composer = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'composer install')));

    expect($probe)->toBeGreaterThan($archive)->toBeLessThan($composer)
        ->and($deployment->fresh()->stack['runtimes']['php'])->toBeInAnyKeyOrder(['runtime' => 'php', 'version' => '8.4.20', 'source' => 'composer.json', 'detail' => '^8.4', 'held_by' => [], 'newest' => null])
        ->and($deployment->fresh()->log)->toContain('PHP 8.4.20 from composer.json (^8.4)');
});

test('when every runtime is installed the deploy finishes in one job and the app takes the release versions at the swap', function () {
    Queue::fake();
    $application = stackApp(['php_version' => '8.3.1']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}'], [], ['php' => ['8.4.20']]);

    stackDeploy($deployment, $transport);

    Queue::assertNotPushed(InstallRuntimes::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->php_version)->toBe('8.4.20')
        ->and($application->fresh()->stack['runtimes']['php']['version'])->toBe('8.4.20')
        ->and(implode("\n", $transport->commands()))->toContain("'PATH=/opt/asdf-data/installs/php/8.4.20/bin:/usr/local/bin:/usr/bin:/bin' -- /bin/bash -c 'composer install");
});

test('a rails deploy records which worker gems its lockfile locks on the deployment and the app', function () {
    Queue::fake();
    $application = stackApp(['type' => ApplicationType::Ruby, 'framework' => 'rails', 'ruby_version' => null]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['.ruby-version' => "3.4.10\n", 'Gemfile' => "source 'https://rubygems.org'\n"], [], ['rails' => '8.0.2', 'solid_queue' => '1.1.5']));

    stackDeploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->stack['gems'])->toBe(['sidekiq' => false, 'solid_queue' => true])
        ->and($application->fresh()->stack['gems'])->toBe(['sidekiq' => false, 'solid_queue' => true]);
});

function stackRailsWithGems(string $found, string $missing): array
{
    $application = stackApp(['type' => ApplicationType::Ruby, 'framework' => 'rails', 'ruby_version' => null]);
    $application->server->forceFill(['os_name' => 'ubuntu'])->save();
    $deployment = Deployment::factory()->for($application)->create();
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['.ruby-version' => "3.4.10\n", 'Gemfile' => "source 'https://rubygems.org'\n"], [], ['rails' => '8.0.2']))
        ->respondTo('grep -ohE', $found)
        ->respondTo("|| echo 'cmake'", $missing);

    stackDeploy($deployment, $transport);

    return [$deployment->fresh(), collect($transport->commands())];
}

test('a rails app whose lockfile has gems that compile with cmake or libclang gets the missing ones installed before bundle install', function () {
    Queue::fake();
    [$deployment, $commands] = stackRailsWithGems("rb_sys\nrugged\n", "cmake\n");
    $install = $commands->search(fn(string $command): bool => str_contains($command, 'apt-get install') && str_contains($command, "'cmake'"));
    $bundle = $commands->search(fn(string $command): bool => str_contains($command, 'bundle install'));

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($install)->toBeInt()
        ->and($install)->toBeLessThan($bundle)
        ->and($commands->contains(fn(string $command): bool => str_contains($command, 'apt-get install') && str_contains($command, 'libclang-dev')))->toBeFalse()
        ->and($deployment->log)->toContain('deps: installing cmake, which rugged needs to build');
});

test('a rails app whose build tools are already there, or whose lockfile needs none, installs no packages for its gems', function (string $found, string $missing) {
    Queue::fake();
    [$deployment, $commands] = stackRailsWithGems($found, $missing);

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($commands->contains(fn(string $command): bool => str_contains($command, 'apt-get install') && (str_contains($command, 'cmake') || str_contains($command, 'libclang-dev'))))->toBeFalse();
})->with([
    'already installed' => ["rugged\n", ''],
    'no such gems' => ['', ''],
]);

test('the gems that need system packages to compile map to each family\'s package', function (string $os, array $packages) {
    $tools = app(NativeGemTools::class);

    expect($tools->needed(Server::factory()->make(['os_name' => $os]), "rb_sys\nrugged\n"))->toBe(['rb_sys' => [$packages[1]], 'rugged' => [$packages[0]]]);
})->with([
    ['ubuntu', ['cmake', 'libclang-dev']],
    ['rocky', ['cmake', 'clang-devel']],
]);

function stackPythonWithPackages(string $found, string $missing): array
{
    $application = stackApp(['type' => ApplicationType::Python, 'framework' => 'django']);
    $application->server->forceFill(['os_name' => 'ubuntu'])->save();
    $deployment = Deployment::factory()->for($application)->create();
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['.python-version' => "3.14.10\n", 'requirements.txt' => "django\npsycopg2==2.9.10\n"], markers: ['manage.py']))
        ->respondTo("ls -1 '/opt/asdf-data/installs/python'", "3.14.10\n")
        ->respondTo('grep -ohiE', $found)
        ->respondTo("|| echo 'build-essential'", $missing);

    stackDeploy($deployment, $transport);

    return [$deployment->fresh(), collect($transport->commands())];
}

test('a Python app whose dependencies build against libpq gets gcc and libpq-dev before they install', function () {
    Queue::fake();
    [$deployment, $commands] = stackPythonWithPackages("psycopg2\n", "gcc\nlibpq-dev\n");
    $install = $commands->search(fn(string $command): bool => str_contains($command, 'apt-get install') && str_contains($command, "'libpq-dev'"));
    $pip = $commands->search(fn(string $command): bool => str_contains($command, 'pip install --python .venv/bin/python -r requirements.txt'));

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($install)->toBeInt()
        ->and($pip)->toBeInt()
        ->and($install)->toBeLessThan($pip)
        ->and($deployment->log)->toContain('deps: installing gcc, libpq-dev, which psycopg2 needs to build');
});

test('a Python app gets a compiler before its first install, for packages with no wheel for its Python', function () {
    Queue::fake();
    [$deployment, $commands] = stackPythonWithPackages('', "build-essential\n");

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($commands->contains(fn(string $command): bool => str_contains($command, 'apt-get install') && str_contains($command, "'build-essential'")))->toBeTrue()
        ->and($deployment->log)->toContain('deps: installing build-essential, which a package with no prebuilt wheel needs to build');
});

test('a Python app whose build tools are already there installs no packages for them', function () {
    Queue::fake();
    [$deployment, $commands] = stackPythonWithPackages("psycopg2\n", '');

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($commands->contains(fn(string $command): bool => str_contains($command, 'apt-get install') && str_contains($command, 'libpq-dev')))->toBeFalse();
});

test('the Python packages that build against system libraries map to each family\'s packages', function (string $os, array $packages) {
    expect(app(NativePythonTools::class)->needed(Server::factory()->make(['os_name' => $os]), "mysqlclient\npsycopg2\n"))->toBe($packages);
})->with([
    'Debian family' => ['ubuntu', ['a package with no prebuilt wheel' => ['build-essential'], 'psycopg2' => ['gcc', 'libpq-dev'], 'mysqlclient' => ['gcc', 'pkg-config', 'default-libmysqlclient-dev']]],
    'Red Hat family' => ['rocky', ['a package with no prebuilt wheel' => ['gcc', 'gcc-c++', 'make'], 'psycopg2' => ['gcc', 'libpq-devel'], 'mysqlclient' => ['gcc', 'pkgconf-pkg-config', 'mariadb-connector-c-devel']]],
]);

test('the server finds those packages in any lockfile or dependency list, and not their prebuilt twins', function () {
    $directory = sys_get_temp_dir() . '/coresmith-native-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/requirements.txt', "Django>=5.1\npsycopg2-binary==2.9.10\n# psycopg2 is not used\n");
    file_put_contents($directory . '/uv.lock', "[[package]]\nname = \"mysqlclient\"\nversion = \"2.2.7\"\n");
    file_put_contents($directory . '/pyproject.toml', "[project]\ndependencies = [\"psycopg2-pool\"]\n");
    mkdir($directory . '/requirements');
    file_put_contents($directory . '/requirements/base.txt', "Django\nPSYCOPG2==2.9.10\n");

    try {
        $output = (string) shell_exec(app(NativePythonTools::class)->find($directory));
    } finally {
        array_map(unlink(...), [...glob($directory . '/*.*') ?: [], ...glob($directory . '/requirements/*') ?: []]);
        rmdir($directory . '/requirements');
        rmdir($directory);
    }

    expect(preg_split('/\s+/', trim($output)))->toBe(['mysqlclient', 'psycopg2']);
});

test('a Django app deploys into a virtualenv, collects its static files, migrates and starts gunicorn on its port', function () {
    Queue::fake();
    $application = stackApp(['type' => ApplicationType::Python, 'framework' => 'django', 'run_migrations' => true]);
    $application->server->forceFill(['os_name' => 'ubuntu'])->save();
    $deployment = Deployment::factory()->for($application)->create();
    $settings = "import os\nALLOWED_HOSTS = os.environ.get('ALLOWED_HOSTS', '').split(',')\nWSGI_APPLICATION = 'shop.wsgi.application'\nSTATIC_ROOT = BASE_DIR / 'staticfiles'\n";
    $probe = ProbeOutput::for([
        '.python-version' => "3.13\n",
        'requirements.txt' => "Django==5.1.4\ngunicorn==23.0.0\n",
        'manage.py' => "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shop.settings')\n",
        'shop/settings.py' => $settings,
        'shop/wsgi.py' => "application = get_wsgi_application()\n",
    ]) . "@@done\n";
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, $probe)
        ->respondTo("ls -1 '/opt/asdf-data/installs/python'", "3.13.18\n");

    stackDeploy($deployment, $transport);

    $commands = collect($transport->commands());
    $position = fn(string $needle): int|false => $commands->search(fn(string $command): bool => str_contains($command, $needle));
    $unit = $transport->uploads()['/etc/systemd/system/coresmith-app-shop-web.service'] ?? '';

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->python_version)->toBe('3.13.18')
        ->and($application->fresh()->stack['language_tooling'])->toBe(['manager' => 'pip', 'server' => 'gunicorn', 'module' => 'shop.wsgi:application', 'collectstatic' => true, 'migrate' => 'django', 'celery' => null])
        ->and(array_map($position, ['uv venv --clear', '-r requirements.txt', 'collectstatic --noinput', 'manage.py migrate --noinput']))->each->toBeInt()
        ->and($position('uv venv --clear'))->toBeLessThan($position('-r requirements.txt'))
        ->and($position('-r requirements.txt'))->toBeLessThan($position('collectstatic --noinput'))
        ->and($position('collectstatic --noinput'))->toBeLessThan($position('manage.py migrate --noinput'))
        ->and($commands->first(fn(string $command): bool => str_contains($command, 'manage.py migrate')))->toContain("'PATH=/var/coresmith/apps/shop/releases/")
        ->and($unit)->toContain("\nExecStart=/var/coresmith/apps/shop/current/.venv/bin/gunicorn --bind 127.0.0.1:8000 --access-logfile - shop.wsgi:application\n")
        ->and($unit)->toContain('Environment="PATH=/var/coresmith/apps/shop/current/.venv/bin:/opt/asdf-data/installs/python/3.13.18/bin:/usr/local/bin:/usr/bin:/bin"')
        ->and($unit)->toContain('Environment="WEB_CONCURRENCY=2"');
});

test('a package that fails to build on the Python Coresmith picked says which one, and how to name the Python the app runs on', function (string $files, string $reason) {
    Queue::fake();
    $application = stackApp(['type' => ApplicationType::Python, 'framework' => 'flask']);
    $application->server->forceFill(['os_name' => 'ubuntu'])->save();
    $deployment = Deployment::factory()->for($application)->create();
    $probe = ProbeOutput::for([...json_decode($files, true), 'requirements.txt' => "Flask==3.0.0\ngreenlet==3.0.1\n", 'app.py' => "app = Flask(__name__)\n"]) . "@@done\n";
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, $probe)
        ->respondTo("ls -1 '/opt/asdf-data/installs/python'", "3.13.18\n3.14.10\n")
        ->respondTo('-r requirements.txt', "Resolved 12 packages in 210ms\n  × Failed to build `greenlet==3.0.1`\n  ├─▶ The build backend returned an error\n  ╰─▶ Call to `setuptools.build_meta.build_wheel` failed (exit status: 1)\n", 1);

    expect(fn() => stackDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe($reason);
})->with([
    'nothing names a version' => ['{}', 'greenlet failed to build on Python 3.14.10, which Coresmith picked because the repository names no Python version. Add a .python-version with the Python the app runs on, for example 3.12, or update greenlet.'],
    'the repository names one' => ['{".python-version":"3.13\n"}', 'greenlet failed to build on Python 3.13.18, the version .python-version names. Update greenlet to a release that supports it, or name an older Python in .python-version.'],
]);

test('a runtime that is not installed hands the deploy to the installer, keeping its lock', function () {
    Queue::fake();
    $application = stackApp();
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}'], [], ['php' => []]);

    stackDeploy($deployment, $transport);

    $fresh = $deployment->fresh();
    $commands = implode("\n", $transport->commands());

    Queue::assertPushedOn('provisioning', InstallRuntimes::class, fn(InstallRuntimes $job): bool => (fn(): array => $this->runtimes)->call($job) === [['runtime' => 'php', 'version' => '8.5.10']]);
    Queue::assertPushed(InstallRuntimes::class, fn(InstallRuntimes $job): bool => Cache::restoreLock('deploy:' . $application->id, (fn(): string => $this->lockOwner)->call($job))->isOwnedByCurrentProcess());

    expect($fresh->status)->toBe(DeploymentStatus::Running)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and($fresh->stale_after->greaterThan(now()->addSeconds(InstallRuntimes::window() - 5)))->toBeTrue()
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeFalse()
        ->and($commands)->not->toContain('composer install')
        ->and($commands)->not->toContain('rm -rf /var/coresmith/apps/shop/releases/' . STACK_RELEASE);
});

test('a version counts as installed only when the binaries it needs are executable, checked in one command per runtime', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}', 'package.json' => '{"scripts":{"build":"vite build"}}'], ['bun.lock'], ['php' => ['8.5.10'], 'bun' => ['1.4.2']]);

    stackDeploy($deployment, $transport);

    $checks = array_values(array_filter($transport->commands(), fn(string $command): bool => str_starts_with($command, 'test -x ')));

    expect(array_slice($checks, 0, 2))->toBe([
        "test -x '/opt/asdf-data/installs/php/8.5.10/bin/php' && test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm' && test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'",
        "test -x '/opt/asdf-data/installs/bun/1.4.2/bin/bun'",
    ])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});

test('a php version whose directory exists without php-fpm is handed to the installer and no fpm unit is written', function () {
    Queue::fake();
    $application = stackApp();
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}'], [], ['php' => ['8.5.10']])
        ->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'", '', 1);

    stackDeploy($deployment, $transport);

    Queue::assertPushed(InstallRuntimes::class, fn(InstallRuntimes $job): bool => (fn(): array => $this->runtimes)->call($job) === [['runtime' => 'php', 'version' => '8.5.10']]);

    expect($transport->uploads())->not->toHaveKey('/etc/systemd/system/coresmith-app-shop-fpm.service')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($deployment->fresh()->log)->toMatch('/PHP 8\.5\.10 from composer\.json \(\^8\.5\).*, not installed yet/')
        ->and(implode("\n", $transport->commands()))->not->toContain('composer install');
});

test('a php version without composer is handed to the installer, which adds it', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}'], [], ['php' => ['8.5.10']])
        ->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'", '', 1);

    stackDeploy($deployment, $transport);

    Queue::assertPushed(InstallRuntimes::class, fn(InstallRuntimes $job): bool => (fn(): array => $this->runtimes)->call($job) === [['runtime' => 'php', 'version' => '8.5.10']]);
});

test('a node.js app that installs with pnpm or yarn is handed to the installer when its node.js version has no corepack', function (string $lockfile) {
    Queue::fake();
    $application = stackApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'node']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['package.json' => '{"scripts":{"build":"vite build"}}', '.nvmrc' => "24\n"], [$lockfile], ['nodejs' => ['24.21.0']])
        ->respondTo("test -x '/opt/asdf-data/installs/nodejs/24.21.0/bin/corepack'", '', 1);

    stackDeploy($deployment, $transport);

    Queue::assertPushed(InstallRuntimes::class, fn(InstallRuntimes $job): bool => (fn(): array => $this->runtimes)->call($job) === [['runtime' => 'nodejs', 'version' => '24.21.0']]);

    expect(implode("\n", $transport->commands()))->not->toContain('corepack pnpm install')
        ->and(implode("\n", $transport->commands()))->not->toContain('corepack yarn install');
})->with(['pnpm' => ['pnpm-lock.yaml'], 'yarn' => ['yarn.lock']]);

test('a node.js app that installs with npm does not need corepack', function () {
    Queue::fake();
    $application = stackApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'node']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['package.json' => '{"scripts":{"build":"vite build"}}', '.nvmrc' => "24\n"], ['package-lock.json'], ['nodejs' => ['24.21.0']])
        ->respondTo("test -x '/opt/asdf-data/installs/nodejs/24.21.0/bin/corepack'", '', 1);

    stackDeploy($deployment, $transport);

    Queue::assertNotPushed(InstallRuntimes::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(implode("\n", $transport->commands()))->not->toContain('/bin/corepack');
});

test('a server with no runtimes installs them before any unit is written or started', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport([], [], ['php' => []]);

    stackDeploy($deployment, $transport);

    Queue::assertPushed(InstallRuntimes::class);

    expect(array_values(array_filter(array_keys($transport->uploads()), fn(string $path): bool => str_starts_with($path, '/etc/systemd') || str_starts_with($path, '/etc/php-fpm.d'))))->toBe([])
        ->and(implode("\n", $transport->commands()))->not->toContain('systemctl');
});

test('a resumed deploy skips the clone, confirms the runtimes and finishes', function () {
    $application = stackApp();
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = stackTransport()->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n");

    stackDeploy($deployment->fresh(), $transport, $lock->owner());

    $commands = implode("\n", $transport->commands());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->stale_after->greaterThan(now()->addMinutes(DetectStaleDeployments::STALE_AFTER_MINUTES - 1)))->toBeTrue()
        ->and($commands)->not->toContain(RepositoryProbe::DIRECTORY_VARIABLE)
        ->and($commands)->not->toContain('git clone')
        ->and($commands)->not->toContain(' archive ')
        ->and($commands)->toContain("'PATH=/opt/asdf-data/installs/php/8.5.10/bin:/usr/local/bin:/usr/bin:/bin' -- /bin/bash -c 'composer install")
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeTrue();
});

test('a resumed rails deploy keeps the worker gems it read before the runtimes were installed', function () {
    $application = stackApp(['type' => ApplicationType::Ruby, 'framework' => 'rails']);
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'stale_after' => now()->addMinute(),
        'release' => STACK_RELEASE,
        'stack' => [
            'runtimes' => ['ruby' => ['runtime' => 'ruby', 'version' => '3.4.10', 'source' => '.ruby-version', 'detail' => '3.4.10']],
            'framework' => ['name' => 'rails', 'major' => 8],
            'tooling' => null,
            'gems' => ['sidekiq' => true, 'solid_queue' => false],
        ],
    ]);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();

    stackDeploy($deployment->fresh(), stackTransport()->respondTo("test -d '/opt/asdf-data/installs/ruby/3.4.10'", "yes\n"), $lock->owner());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->stack['gems'])->toBe(['sidekiq' => true, 'solid_queue' => false]);
});

test('a resumed deploy keeps the runtime that serves the app ahead of the one that builds its assets', function (array $runtimes) {
    $application = stackApp();
    $deployment = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'stale_after' => now()->addMinute(),
        'release' => STACK_RELEASE,
        'stack' => [
            'runtimes' => $runtimes,
            'framework' => ['name' => 'laravel', 'major' => 12],
            'tooling' => ['manager' => 'bun', 'locked' => true, 'build' => true, 'start' => false, 'manager_version' => null, 'manifest' => true, 'ssr' => false],
        ],
    ]);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = stackTransport()
        ->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n")
        ->respondTo("test -d '/opt/asdf-data/installs/bun/1.4.2'", "yes\n");

    stackDeploy($deployment->fresh(), $transport, $lock->owner());

    $commands = implode("\n", $transport->commands());
    $path = "'PATH=/opt/asdf-data/installs/php/8.5.10/bin:/opt/asdf-data/installs/bun/1.4.2/bin:/usr/local/bin:/usr/bin:/bin' -- /bin/bash -c ";

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($commands)->toContain($path . "'composer install")
        ->and($commands)->toContain($path . "'bun install --frozen-lockfile'")
        ->and($commands)->toContain($path . "'bun run build'");
})->with([
    'stored in the order it was resolved' => [[
        'php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null],
        'bun' => ['runtime' => 'bun', 'version' => '1.4.2', 'source' => 'default', 'detail' => null],
    ]],
    'stored in the order mysql reads it back' => [[
        'bun' => ['runtime' => 'bun', 'version' => '1.4.2', 'source' => 'default', 'detail' => null],
        'php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null],
    ]],
]);

test('a resumed deploy whose deployment was reclaimed after the job started stops without touching anything', function () {
    $application = stackApp(['status' => ApplicationStatus::Deploying]);
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $running = $deployment->fresh();
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Failed->value, 'log' => 'Deployment timed out with no response after 20 minutes.']);
    $reclaimed = $deployment->fresh()->getAttributes();
    $transport = stackTransport()->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n");

    stackDeploy($running, $transport, $lock->owner());

    expect($deployment->fresh()->getAttributes())->toBe($reclaimed)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and($transport->commands())->toBe([]);
});

test('a resumed deploy whose deployment was reclaimed and whose lock was released writes nothing and sends no failure', function () {
    Event::fake([DeploymentFailed::class]);
    $application = stackApp(['status' => ApplicationStatus::Deploying]);
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $running = $deployment->fresh();
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Failed->value, 'log' => 'Deployment timed out with no response after 20 minutes.']);
    Cache::lock('deploy:' . $application->id)->forceRelease();
    $reclaimed = $deployment->fresh()->getAttributes();
    $transport = stackTransport();

    stackDeploy($running, $transport, $lock->owner());

    expect($deployment->fresh()->getAttributes())->toBe($reclaimed)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toBe('Deployment timed out with no response after 20 minutes.')
        ->and($deployment->fresh()->failure_reason)->toBeNull()
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and($transport->commands())->toBe([]);

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a resumed deploy that was reclaimed while the install waited does nothing', function () {
    $deployment = Deployment::factory()->for(stackApp())->create(['status' => DeploymentStatus::Failed, 'release' => STACK_RELEASE]);
    $transport = stackTransport();

    stackDeploy($deployment, $transport, 'owner-1');

    expect($transport->commands())->toBe([])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
});

test('a resumed deploy whose lock another deploy now holds stops without touching anything live', function () {
    $application = stackApp(['status' => ApplicationStatus::Deploying]);
    $deployment = stackPausedDeployment($application);
    Cache::lock('deploy:' . $application->id, 60, 'someone-else')->get();
    $transport = stackTransport();

    expect(fn() => stackDeploy($deployment->fresh(), $transport, 'owner-1'))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('The deploy lock expired while the runtimes installed, so another deploy may have started. Deploy again.')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/shop/releases/' . STACK_RELEASE)
        ->and(implode("\n", $transport->commands()))->not->toContain('mv -Tf')
        ->and(Cache::lock('deploy:' . $application->id, 60, 'someone-else')->isOwnedByCurrentProcess())->toBeTrue();
});

test('a resumed deploy whose app was removed while its runtimes installed is cancelled and gives back its lock', function () {
    Event::fake([DeploymentFailed::class]);
    $application = stackApp(['status' => ApplicationStatus::Deploying]);
    $deployment = stackPausedDeployment($application);
    $deployment->forceFill(['log' => '[10:00:00] deps: installing PHP 8.5.10'])->save();
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $application->delete();
    $transport = stackTransport();

    stackDeploy($deployment->fresh(), $transport, $lock->owner());

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->log)->toBe("[10:00:00] deps: installing PHP 8.5.10\nThe app was removed while it deployed.")
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($transport->commands())->toBe([])
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeTrue();

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a queued deploy whose app was removed before it ran is cancelled without touching the server or the lock', function () {
    $application = stackApp();
    $deployment = Deployment::factory()->for($application)->create();
    Cache::lock('deploy:' . $application->id, 600, 'someone-else')->get();
    $application->delete();
    $transport = stackTransport();

    stackDeploy($deployment->fresh(), $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toBe('The app was removed while it deployed.')
        ->and($transport->commands())->toBe([])
        ->and(Cache::lock('deploy:' . $application->id, 600, 'someone-else')->isOwnedByCurrentProcess())->toBeTrue();
});

test('a resumed deploy of a removed app that was already reclaimed keeps its failure and gives back its lock', function () {
    $application = stackApp();
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $running = $deployment->fresh();
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Failed->value, 'failure_reason' => 'reclaimed']);
    $reclaimed = $deployment->fresh()->getAttributes();
    $application->delete();

    stackDeploy($running, stackTransport(), $lock->owner());

    expect($deployment->fresh()->getAttributes())->toBe($reclaimed)
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeTrue();
});

test('a resumed deploy whose runtime is still missing stops before changing anything', function () {
    $application = stackApp();
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = stackTransport()->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "no\n");

    expect(fn() => stackDeploy($deployment->fresh(), $transport, $lock->owner()))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->failure_reason)->toContain('PHP 8.5.10 is still not installed on')
        ->and(implode("\n", $transport->commands()))->not->toContain('mv -Tf');
});

test('a release that changes the php version restarts php-fpm instead of reloading it', function () {
    Queue::fake();
    $application = stackApp(['php_version' => '8.4.20', 'current_release' => '20260901000000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}'], [], ['php' => ['8.4.20', '8.5.10']]);

    stackDeploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("systemctl restart 'coresmith-app-shop-fpm'")
        ->and($commands)->not->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'");
});

test('a release that changes the php version moves the queue worker onto it through the unit PATH', function () {
    Queue::fake();
    $application = stackApp([
        'php_version' => '8.4.20',
        'current_release' => '20260901000000',
        'processes' => [['name' => 'queue', 'command' => '/opt/asdf-data/installs/php/8.4.20/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600']],
    ]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}'], [], ['php' => ['8.4.20', '8.5.10']]);

    stackDeploy($deployment, $transport);

    $unit = $transport->uploads()['/etc/systemd/system/coresmith-app-shop-queue.service'] ?? '';

    expect($unit)->toContain('Environment="PATH=/opt/asdf-data/installs/php/8.5.10/bin:/usr/local/bin:/usr/bin:/bin"')
        ->and($unit)->toContain("\nExecStart=/usr/bin/env php artisan queue:work --sleep=3 --tries=3 --max-time=3600\n")
        ->and($unit)->not->toContain('8.4.20')
        ->and(implode("\n", $transport->commands()))->toContain("systemctl restart 'coresmith-app-shop-queue'");
});

test('a release on the same php version reloads php-fpm gracefully', function () {
    Queue::fake();
    $application = stackApp(['php_version' => '8.5.10', 'current_release' => '20260901000000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}'], [], ['php' => ['8.5.10']]);

    stackDeploy($deployment, $transport);

    expect(implode("\n", $transport->commands()))->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'");
});

test('a release that changes only the node.js version of a php app reloads php-fpm gracefully', function () {
    Queue::fake();
    $application = stackApp(['php_version' => '8.5.10', 'node_version' => '22.23.2', 'current_release' => '20260901000000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = stackTransport(
        ['composer.json' => '{"require":{"php":"^8.5"}}', 'package.json' => '{"scripts":{"build":"vite build"},"engines":{"node":"24"}}'],
        ['package-lock.json'],
        ['php' => ['8.5.10'], 'nodejs' => ['22.23.2', '24.21.0']],
    );

    stackDeploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($application->fresh()->node_version)->toBe('24.21.0')
        ->and($application->fresh()->php_version)->toBe('8.5.10')
        ->and($commands)->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'")
        ->and($commands)->not->toContain("systemctl restart 'coresmith-app-shop-fpm'");
});

test('a version nothing can satisfy fails before anything is installed or changed', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":">=9"}}']);

    expect(fn() => stackDeploy($deployment, $transport))->toThrow(RuntimeUnavailable::class);

    Queue::assertNotPushed(InstallRuntimes::class);

    expect($deployment->fresh()->failure_reason)->toBe('require.php in composer.json asks for PHP >=9, which matches no version Coresmith can install (8.1–8.5). Change it, then deploy.')
        ->and(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/shop/releases/' . STACK_RELEASE);
});

test('a deploy that fails on its first command was already marked running with a reclaim deadline', function () {
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "97\n");

    expect(fn() => stackDeploy($deployment, $transport))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->started_at)->not->toBeNull()
        ->and($deployment->fresh()->stale_after)->not->toBeNull();
});

test('the timings leave every long job room to finish before anything reclaims it', function () {
    $deployment = Deployment::factory()->for(stackApp())->create();
    $deploy = new DeployApplication($deployment, STACK_RELEASE);
    $install = new InstallRuntimes($deployment, STACK_RELEASE, [], 'owner-1');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($deploy->timeout)->toBe(780)
        ->and($install->timeout)->toBe(2700)
        ->and(DetectStaleDeployments::STALE_AFTER_MINUTES * 60)->toBeGreaterThan($deploy->timeout)
        ->and(DeployApplication::LOCK_SECONDS)->toBeGreaterThan(2 * (InstallRuntimes::window() + DeployApplication::TIMEOUT))
        ->and($retryAfter)->toBeGreaterThan($install->timeout + 60)
        ->and(config('horizon.defaults.deployments.timeout'))->toBeGreaterThanOrEqual(DeployApplication::TIMEOUT)->toBeLessThan($retryAfter)
        ->and(config('horizon.defaults.provisioning.timeout'))->toBeGreaterThanOrEqual(InstallRuntimes::TIMEOUT)->toBeLessThan($retryAfter);
});

test('a deploy that cannot record that it started gives its lock back', function (string $record) {
    $application = stackApp();
    $deployment = Deployment::factory()->for($application)->create();
    $unstarted = $deployment->fresh()->getAttributes();
    $broken = $record === 'deployment' ? $deployment : $application;
    $transport = stackTransport();

    $broken::saving(function (Model $saving) use ($broken): void {
        if ($saving->is($broken)) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(fn() => stackDeploy($deployment, $transport))->toThrow(RuntimeException::class, 'The database went away.');

    expect(Cache::lock('deploy:' . $application->id, 10)->get())->toBeTrue()
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->fresh()->getAttributes())->toBe($unstarted)
        ->and($transport->commands())->toBe([]);
})->with(['deployment', 'application']);

test('a connection that drops while the repository is read is reopened and the read runs once more', function (string $needle) {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport([], [], ['php' => ['8.5.10']])->dropConnectionOn($needle);

    stackDeploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('the SSH connection dropped during clone, reconnecting and running it once more');
})->with([
    'the repository probe' => [RepositoryProbe::DIRECTORY_VARIABLE],
    'the installed versions' => ["ls -1 '/opt/asdf-data/installs/php'"],
]);

test('a connection that drops while a resumed deploy confirms its runtimes is reopened and the check runs once more', function () {
    $application = stackApp();
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = stackTransport()
        ->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n")
        ->dropConnectionOn("test -d '/opt/asdf-data/installs/php/8.5.10'");

    stackDeploy($deployment->fresh(), $transport, $lock->owner());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain('the SSH connection dropped during deps, reconnecting and running it once more');
});

test('a step the connection dropped is stopped on the server before it runs once more', function (string $step) {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}', 'package.json' => '{"scripts":{"build":"vite build"}}'], ['bun.lock'])->dropConnectionOn($step);

    stackDeploy($deployment, $transport);

    $commands = $transport->commands();
    $runs = array_keys(array_filter($commands, fn(string $command): bool => str_contains($command, $step)));
    $stops = array_keys(array_filter($commands, fn(string $command): bool => str_contains($command, 'systemctl stop coresmith-build-shop-' . STACK_RELEASE . '.service || true')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($runs)->toHaveCount(2)
        ->and($stops)->toHaveCount(1)
        ->and($stops[0])->toBeGreaterThan($runs[0])->toBeLessThan($runs[1]);
})->with([
    'installing dependencies' => ['bun install --frozen-lockfile'],
    'building' => ['bun run build'],
]);

test('a resumed deploy whose runtime check dropped stops no build', function () {
    $application = stackApp();
    $deployment = stackPausedDeployment($application);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = stackTransport()
        ->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n")
        ->dropConnectionOn("test -d '/opt/asdf-data/installs/php/8.5.10'");

    stackDeploy($deployment->fresh(), $transport, $lock->owner());

    $runtimeChecks = array_filter($transport->commands(), fn(string $command): bool => str_contains($command, "test -d '/opt/asdf-data/installs/php/8.5.10'"));
    $stopsBeforeDeps = array_filter(
        array_slice($transport->commands(), 0, (int) array_key_last($runtimeChecks)),
        fn(string $command): bool => str_contains($command, 'systemctl stop'),
    );

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($runtimeChecks)->toHaveCount(2)
        ->and($stopsBeforeDeps)->toBe([]);
});

test('a laravel app with a vite build installs and builds its assets in the deploy', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}', 'package.json' => '{"scripts":{"build":"vite build"}}'], ['bun.lock']);

    stackDeploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("/opt/asdf-data/installs/bun/1.4.2/bin:/usr/local/bin:/usr/bin:/bin' -- /bin/bash -c 'bun install --frozen-lockfile'")
        ->and($commands)->toContain('bun run build')
        ->and(strpos($commands, 'bun run build'))->toBeLessThan(strpos($commands, 'php artisan config:cache'));
});

test('the deploy log says where each version came from and whether it is still supported', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00'));
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.4"}}'], [], ['php' => ['8.4.20']]);

    stackDeploy($deployment, $transport);

    expect($deployment->fresh()->log)->toContain('PHP 8.4.20 from composer.json (^8.4), active until Dec 2026');
});

function stackNetteLock(): string
{
    return (string) json_encode(['packages' => [['name' => 'nette/utils', 'version' => 'v4.0.5', 'require' => ['php' => '8.0 - 8.4']]]]);
}

test('a lock that holds php back is named in the deploy log and kept on the deployment', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.2"}}', 'composer.lock' => stackNetteLock()], [], ['php' => ['8.4.20', '8.5.10']]);

    stackDeploy($deployment, $transport);

    expect($deployment->fresh()->stack['runtimes']['php'])->toMatchArray(['version' => '8.4.20', 'held_by' => ['nette/utils 4.0.5'], 'newest' => '8.5.10'])
        ->and($deployment->fresh()->log)->toContain('PHP 8.4.20, the newest your composer.lock allows: nette/utils 4.0.5 stops at 8.4');
});

test('a lock that rules out what composer.json asks for stops the deploy before anything installs', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(stackApp())->create();
    $transport = stackTransport(['composer.json' => '{"require":{"php":"^8.5"}}', 'composer.lock' => stackNetteLock()]);

    expect(fn() => stackDeploy($deployment, $transport))->toThrow(RuntimeUnavailable::class);

    Queue::assertNotPushed(InstallRuntimes::class);
    expect($deployment->fresh()->failure_reason)->toBe('require.php in composer.json asks for PHP ^8.5, but composer.lock holds nette/utils 4.0.5 (PHP 8.0 - 8.4). Run composer update nette/utils, commit composer.lock, then deploy.')
        ->and(collect($transport->commands())->contains(fn(string $command): bool => str_contains($command, 'composer install')))->toBeFalse();
});
