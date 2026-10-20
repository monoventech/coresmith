<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Models\Server;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\ProcessStartAdvice;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function startManager(): ProcessManager
{
    $asdf = new AsdfManager;

    return new ProcessManager(new SystemdUnitBuilder($asdf), new FpmPoolBuilder($asdf));
}

function startConnection(FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']), $transport))->connect();
}

function startApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'base_port' => 8100,
        'processes' => [['name' => 'queue', 'command' => '/opt/asdf-data/installs/php/8.5.5/bin/php artisan queue:work']],
    ], $attributes));
}

test('every unit is restarted under the name it was installed with', function () {
    $transport = new FakeSSHTransport;

    startManager()->refresh(startConnection($transport), startApp());

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("systemctl enable 'coresmith-app-shop-fpm'")
        ->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'")
        ->toContain("systemctl enable 'coresmith-app-shop-queue'")
        ->toContain("systemctl restart 'coresmith-app-shop-queue'")
        ->not->toContain('php-fpm-coresmith-app-shop')
        ->not->toContain('coresmith-app-shop-worker');
});

test('a node app restarts its web unit and has no fpm unit', function () {
    $transport = new FakeSSHTransport;
    $application = startApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'php_version' => null, 'node_version' => '22.1.0', 'js_runtime' => 'node', 'processes' => null]);

    startManager()->refresh(startConnection($transport), $application);

    expect(implode("\n", $transport->commands()))->toContain("systemctl restart 'coresmith-app-shop-web'")
        ->not->toContain('-fpm');
});

test('the fpm unit can be reloaded gracefully', function () {
    expect((new FpmPoolBuilder(new AsdfManager))->unit(startApp()))->toContain('ExecReload=/bin/kill -USR2 $MAINPID');
});

test('only the php-fpm or web unit serves an app, never a worker', function () {
    $php = new Application(['slug' => 'shop', 'type' => ApplicationType::Php, 'php_version' => '8.5.5', 'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);
    $node = new Application(['slug' => 'shop', 'type' => ApplicationType::Js, 'js_runtime' => 'node', 'node_version' => '22.1.0', 'processes' => [['name' => 'worker', 'command' => 'node worker.js']]]);

    expect(startManager()->serves($php, 'coresmith-app-shop-fpm'))->toBeTrue()
        ->and(startManager()->serves($php, 'coresmith-app-shop-queue'))->toBeFalse()
        ->and(startManager()->serves($php, 'coresmith-app-shop-web'))->toBeFalse()
        ->and(startManager()->serves($node, 'coresmith-app-shop-web'))->toBeTrue()
        ->and(startManager()->serves($node, 'coresmith-app-shop-worker'))->toBeFalse()
        ->and(startManager()->serves($node, 'coresmith-app-shop-fpm'))->toBeFalse();
});

test('units that come up active pass after a short grace period', function () {
    $transport = new FakeSSHTransport;

    startManager()->verifyStarted(startConnection($transport), startApp());

    Sleep::assertSequence([Sleep::for(ProcessManager::START_GRACE_SECONDS)->seconds()]);

    expect(implode("\n", $transport->commands()))->toContain("systemctl is-active 'coresmith-app-shop-fpm'")
        ->toContain("systemctl is-active 'coresmith-app-shop-queue'");
});

test('a unit that is not running fails with its journal and advice for what the journal says', function (string $journal, string $advice) {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-queue'", $journal);

    try {
        startManager()->verifyStarted(startConnection($transport), startApp());
        $this->fail('A unit that was not running passed the start check.');
    } catch (ProcessDidNotStart $e) {
        expect($e->unit)->toBe('coresmith-app-shop-queue')
            ->and($e->state)->toBe('failed')
            ->and($e->journal)->toBe($journal)
            ->and($e->getMessage())->toContain('coresmith-app-shop-queue did not start')
            ->and($e->getMessage())->toContain($advice);
    }
})->with([
    'port taken' => ['Error: listen EADDRINUSE: address already in use :::8100', 'Port 8100 is already taken by another process.'],
    'cannot bind' => ['Error: listen EACCES: permission denied 0.0.0.0:8100', 'The process was not allowed to bind its port.'],
    'missing module' => ["Error: Cannot find module 'express'", 'A Node module is missing.'],
    'unreadable files' => ['PHP Warning: require(/var/coresmith/apps/shop/current/vendor/autoload.php): Failed to open stream: Permission denied', 'The process could not read its files.'],
    'missing entry point' => ['bun: No such file or directory: server.js', 'The entry point was not found.'],
    'syntax error' => ['PHP Parse error: syntax error, unexpected token "}"', 'The code has a syntax error.'],
    'missing environment file' => ['coresmith-app-shop-queue.service: Failed to load environment files: No such file or directory', "The app's environment file is missing from the server. Deploy again to write it."],
    'missing linux user' => ['coresmith-app-shop-queue.service: Failed to determine user credentials: No such process', "The app's Linux user is missing from the server. Deploy again to create it."],
    'sandbox not set up' => ['coresmith-app-shop-queue.service: Failed to set up mount namespacing: /home/coresmith-app-shop: No such file or directory', "systemd could not set up the app's sandbox."],
    'rails in development' => ["*  Environment: development\n! Unable to load application: LoadError: cannot load such file -- debug/prelude", 'This Rails app started in development mode. Set RAILS_ENV=production in its environment variables, then deploy again.'],
    'a missing ruby file in production' => ["*  Environment: production\n! Unable to load application: LoadError: cannot load such file -- stripe", 'The process log says why.'],
    'anything else' => ['Killed', 'The process log says why.'],
]);

function startRailsApp(string $env = "SECRET_KEY_BASE=0f9c1e5a7b3d2c4e6f8a0b1c3d5e7f9a\n"): Application
{
    return startApp([
        'type' => ApplicationType::Ruby,
        'framework' => 'rails',
        'php_version' => null,
        'ruby_version' => '3.3.6',
        'processes' => null,
        'env_variables' => $env,
    ]);
}

function startFailure(Application $application, string $log): ProcessDidNotStart
{
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-web'", "activating\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-web'", startJournal() . "\n")
        ->respondTo("tail -n 200 '/var/coresmith/logs/shop/web.log' 2>/dev/null || true", $log);

    try {
        startManager()->verifyStarted(startConnection($transport), $application);
    } catch (ProcessDidNotStart $e) {
        return $e;
    }

    throw new RuntimeException('A unit that was not running passed the start check.');
}

function startJournal(): string
{
    return 'coresmith-app-shop-web.service: Main process exited, code=exited, status=1/FAILURE';
}

function startPumaCrash(): array
{
    $release = '/var/coresmith/apps/shop/releases/20261019-204405';

    return [
        'Puma starting in single mode...',
        '* Puma version: 6.6.0 ("Return to Forever")',
        '* Ruby version: ruby 3.3.6 (2024-11-05 revision 75015d4c1f) +YJIT +PRISM [x86_64-linux]',
        '*  Min threads: 3',
        '*  Max threads: 3',
        '*  Environment: production',
        '*          PID: 41250',
        '! Unable to load application: LoadError: cannot load such file -- debug/prelude',
        "bundler: failed to load command: puma ({$release}/vendor/bundle/ruby/3.3.0/bin/puma)",
        "{$release}/vendor/bundle/ruby/3.3.0/gems/bootsnap-1.18.4/lib/bootsnap/load_path_cache/core_ext/kernel_require.rb:30:in 'Kernel#require': cannot load such file -- debug/prelude (LoadError)",
        ...array_map(fn(int $line): string => "\tfrom {$release}/vendor/bundle/ruby/3.3.0/gems/railties-8.0.2/lib/rails/initializable.rb:{$line}:in 'run'", range(11, 51)),
        "\tfrom /opt/asdf-data/installs/ruby/3.3.6/bin/bundle:25:in '<main>'",
    ];
}

function startExcerpt(array $crash): string
{
    return startJournal() . "\n" . implode("\n", [...array_slice($crash, 9, 15), '...', ...array_slice($crash, -10)]);
}

function startRequests(): array
{
    return [
        '[5f2a] Started GET "/favicon.ico" for 127.0.0.1 at 2026-10-19 20:44:05 +0000',
        '[5f2a] ActionController::RoutingError (No route matches [GET] "/favicon.ico"):',
        '[9c1d] Started GET "/products/9" for 127.0.0.1 at 2026-10-19 20:44:07 +0000',
        '[9c1d] Processing by ProductsController#show as HTML',
        '[9c1d] Completed 404 Not Found in 3ms (ActiveRecord: 0.4ms (1 query, 0 cached) | GC: 0.0ms)',
    ];
}

test('a process that did not start shows its most recent error and how it ended, not only the end of a backtrace', function (array $before) {
    $crash = startPumaCrash();
    $e = startFailure(startRailsApp(), implode("\n", [...$before, ...$crash]) . "\n");

    expect($e->journal)->toBe(startExcerpt($crash))
        ->and($e->journal)->not->toContain('Puma starting in single mode')
        ->and($e->journal)->not->toContain('initializable.rb:25:')
        ->and($e->journal)->not->toContain('initializable.rb:42:');
})->with([
    'a single boot attempt' => [[]],
    'after older lines that look like no error' => [array_map(fn(int $line): string => "[7e3b] Rendered products/index.html.erb within layouts/application (Duration: {$line}.1ms | GC: 0.0ms)", range(1, 148))],
]);

test('a rails log with a request that was not found before the crash shows the LoadError, not the request', function () {
    $crash = startPumaCrash();
    $e = startFailure(startRailsApp(), implode("\n", [...startRequests(), ...$crash]) . "\n");

    expect($e->journal)->toBe(startExcerpt($crash))
        ->and($e->journal)->not->toContain('Completed 404 Not Found')
        ->and($e->journal)->not->toContain('RoutingError');
});

test('the excerpt starts at the error message, not at a stack frame that names an error after it', function (array $trace, string $message, string $frame) {
    $e = startFailure(startRailsApp(), implode("\n", $trace) . "\n");

    expect($e->journal)->toStartWith(startJournal() . "\n" . $message . "\n")
        ->and($e->journal)->toContain("\n" . $frame . "\n");
})->with([
    'node' => [
        [
            'node:internal/modules/esm/get_format:160',
            '  throw new ERR_UNKNOWN_FILE_EXTENSION(ext, filepath);',
            '        ^',
            '',
            'TypeError [ERR_UNKNOWN_FILE_EXTENSION]: Unknown file extension ".ts" for /var/coresmith/apps/shop/current/server.ts',
            '    at new NodeError (node:internal/errors:405:5)',
            '    at Object.getFileProtocolModuleFormat [as file:] (node:internal/modules/esm/get_format:160:9)',
            '    at defaultGetFormat (node:internal/modules/esm/get_format:203:36)',
            '    at defaultLoad (node:internal/modules/esm/load:141:22)',
            '    at async ModuleLoader.load (node:internal/modules/esm/loader:409:7) {',
            "  code: 'ERR_UNKNOWN_FILE_EXTENSION'",
            '}',
            '',
            'Node.js v20.11.1',
        ],
        'TypeError [ERR_UNKNOWN_FILE_EXTENSION]: Unknown file extension ".ts" for /var/coresmith/apps/shop/current/server.ts',
        '    at new NodeError (node:internal/errors:405:5)',
    ],
    'ruby' => [
        [
            "/var/coresmith/apps/shop/current/app/errors/payment_error.rb:6:in 'Hash#fetch': key not found: \"code\" (KeyError)",
            "\tfrom /var/coresmith/apps/shop/current/app/errors/payment_error.rb:6:in 'PaymentError.from_response'",
            "\tfrom /var/coresmith/apps/shop/current/app/clients/payments_client.rb:22:in 'PaymentsClient#verify!'",
            "\tfrom /var/coresmith/apps/shop/current/config/initializers/payments.rb:3:in '<main>'",
            ...array_map(fn(int $line): string => "\tfrom /var/coresmith/apps/shop/current/vendor/bundle/ruby/3.4.0/gems/railties-8.0.2/lib/rails/initializable.rb:{$line}:in 'Rails::Initializable::Initializer#run'", range(11, 40)),
            "\tfrom /var/coresmith/apps/shop/current/config/environment.rb:5:in '<main>'",
            "\tfrom /var/coresmith/apps/shop/current/bin/jobs:3:in 'Kernel#require_relative'",
            "\tfrom /var/coresmith/apps/shop/current/bin/jobs:3:in '<main>'",
        ],
        "/var/coresmith/apps/shop/current/app/errors/payment_error.rb:6:in 'Hash#fetch': key not found: \"code\" (KeyError)",
        "\tfrom /var/coresmith/apps/shop/current/app/errors/payment_error.rb:6:in 'PaymentError.from_response'",
    ],
    'rails' => [
        [
            '[5f2a] Started GET "/products/9" for 127.0.0.1 at 2026-10-19 20:44:05 +0000',
            '[5f2a] Processing by ProductsController#show as HTML',
            '[5f2a] Completed 500 Internal Server Error in 18ms (ActiveRecord: 0.6ms | Allocations: 4321)',
            "[5f2a] ActionView::Template::Error (undefined method `price' for nil):",
            '[5f2a]      8:   <p><%= number_to_currency(@product.price) %></p>',
            "[5f2a] actionview (7.1.3) lib/action_view/base.rb:250:in `public_send'",
            "[5f2a] actionpack (7.1.3) lib/action_dispatch/middleware/debug_exceptions.rb:29:in `call'",
            "[5f2a] actionpack (7.1.3) lib/action_dispatch/middleware/show_exceptions.rb:31:in `call'",
            "[5f2a] puma (6.4.2) lib/puma/thread_pool.rb:155:in `block in spawn_thread'",
        ],
        "[5f2a] ActionView::Template::Error (undefined method `price' for nil):",
        "[5f2a] actionpack (7.1.3) lib/action_dispatch/middleware/debug_exceptions.rb:29:in `call'",
    ],
    'php' => [
        [
            'PHP Fatal error:  Uncaught PDOException: SQLSTATE[HY000] [2002] Connection refused in /var/coresmith/apps/shop/current/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php:65',
            'Stack trace:',
            "#0 /var/coresmith/apps/shop/current/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php(65): PDO->__construct('mysql:host=127....', 'shop', Object(SensitiveParameterValue), Array)",
            "#1 /var/coresmith/apps/shop/current/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php(44): Illuminate\\Database\\Connectors\\Connector->createPdoConnection('mysql:host=127....', 'shop', Object(SensitiveParameterValue), Array)",
            '#2 [internal function]: Illuminate\Foundation\Bootstrap\HandleExceptions->handleException(Object(PDOException))',
            '#3 {main}',
            '  thrown in /var/coresmith/apps/shop/current/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php on line 65',
        ],
        'PHP Fatal error:  Uncaught PDOException: SQLSTATE[HY000] [2002] Connection refused in /var/coresmith/apps/shop/current/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php:65',
        '#2 [internal function]: Illuminate\Foundation\Bootstrap\HandleExceptions->handleException(Object(PDOException))',
    ],
]);

test('the most recent named error is shown, not an earlier one', function () {
    $log = ['PDOException: SQLSTATE[HY000] [2002] Connection refused', 'boot step 1', 'boot step 2', 'NameError: uninitialized constant Stripe', 'boot step 3'];
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toBe(startJournal() . "\nNameError: uninitialized constant Stripe\nboot step 3");
});

test('a line that names an error is shown before any line with a looser word, wherever that line is', function (string $error) {
    $log = [...array_map(fn(int $line): string => "boot step {$line}", range(1, 20)), 'Job FAILED after 3 attempts', $error, 'the retry failed too', ...array_map(fn(int $line): string => "boot step {$line}", range(24, 43))];
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toStartWith(startJournal() . "\n" . $error . "\nthe retry failed too\n")
        ->and($e->journal)->not->toContain('Job FAILED');
})->with([
    'an error class' => 'NameError: uninitialized constant Stripe',
    'an exception class' => 'PDOException: SQLSTATE[HY000] [2002] Connection refused',
    'a namespaced exception class' => 'Illuminate\Database\QueryException: SQLSTATE[42S02]: Base table or view not found',
    'a file ruby cannot load' => 'cannot load such file -- debug/prelude',
    'a php fatal error' => 'PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted',
    'a fatal: line' => 'fatal: not a git repository (or any of the parent directories): .git',
    'a permission refused' => 'open /var/run/app.sock: Permission denied',
]);

test('with no line that names an error, the last line with a looser word is shown, in any case', function (string $error) {
    $log = ['Job FAILED after 3 attempts', ...array_map(fn(int $line): string => "boot step {$line}", range(1, 20)), $error, ...array_map(fn(int $line): string => "boot step {$line}", range(22, 41))];
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toStartWith(startJournal() . "\n" . $error . "\nboot step 22\n")
        ->and($e->journal)->not->toContain('Job FAILED');
})->with([
    'error' => 'ERROR: database "shop" does not exist',
    'exception' => 'Unhandled exception in thread main',
    'cannot' => "Cannot find module 'express'",
    'failed' => 'the connection FAILED',
    'fatal' => 'Fatal signal 11 received',
    'not found' => 'sh: 1: vite: not found',
    'denied' => 'ACCESS DENIED for user shop',
]);

test('rails request lines never count as the error, even when they are the only lines with a looser word', function () {
    $requests = [
        '[5f2a] Started GET "/errors/not_found" for 127.0.0.1 at 2026-10-19 20:44:05 +0000',
        '[5f2a] Processing by ErrorsController#not_found as HTML',
        '[5f2a] Completed 404 Not Found in 3ms (ActiveRecord: 0.0ms (0 queries, 0 cached) | GC: 0.0ms)',
    ];
    $log = [...array_map(fn(int $line): string => "boot step {$line}", range(1, 30)), ...$requests, ...array_map(fn(int $line): string => "boot step {$line}", range(31, 35))];
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toBe(startJournal() . "\n" . implode("\n", array_slice($log, -30)));
});

test('the error block and the end of the log are shown as one when they meet or overlap', function (int $lines, int $error, array $shown) {
    $log = array_map(fn(int $line): string => $line === $error ? "Error: connect ECONNREFUSED 127.0.0.1:5432 (line {$line})" : "boot step {$line}", range(1, $lines));
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");
    $expected = array_map(fn(int|string $line): string => is_int($line) ? $log[$line - 1] : $line, $shown);

    expect($e->journal)->toBe(startJournal() . "\n" . implode("\n", $expected));
})->with([
    'the error block runs to the end of the log' => [20, 15, range(15, 20)],
    'the last lines follow straight after the error block' => [25, 1, range(1, 25)],
    'a single line between them is left out' => [26, 1, [...range(1, 15), '...', ...range(17, 26)]],
]);

test('a log with nothing that looks like an error shows its last 30 lines', function () {
    $log = array_map(fn(int $line): string => "boot step {$line}", range(1, 40));
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toBe(startJournal() . "\n" . implode("\n", array_slice($log, -30)));
});

test('a process that did not start adds its own error after the journal, with secrets removed', function () {
    $log = "Puma starting in single mode...\n*  Environment: production\n! Unable to load application: LoadError: cannot load such file -- debug/prelude\nsecret_key_base: 0f9c1e5a7b3d2c4e6f8a0b1c3d5e7f9a\n";
    $e = startFailure(startRailsApp(), $log);

    expect($e->journal)->toBe(startJournal() . "\n! Unable to load application: LoadError: cannot load such file -- debug/prelude\nsecret_key_base: [REDACTED:SECRET_KEY_BASE]")
        ->and($e->journal)->not->toContain('0f9c1e5a7b3d2c4e6f8a0b1c3d5e7f9a');
});

test('a key the error block would cut in two is still removed whole', function () {
    $key = array_map(fn(int $line): string => str_repeat(chr(65 + $line % 26), 64), range(1, 30));
    $after = array_map(fn(int $line): string => "boot step {$line}", range(1, 20));
    $log = ['Fatal: could not read the signing key', '-----BEGIN PRIVATE KEY-----', ...$key, '-----END PRIVATE KEY-----', ...$after];
    $e = startFailure(startRailsApp(), implode("\n", $log) . "\n");

    expect($e->journal)->toBe(startJournal() . "\n" . implode("\n", ['Fatal: could not read the signing key', '[REDACTED_PRIVATE_KEY]', ...$after]))
        ->and($e->journal)->not->toContain($key[0]);
});

test('a rails app that sets its own RAILS_ENV and cannot load a gem is told its gems were installed for production only', function () {
    $e = startFailure(startRailsApp("RAILS_ENV=development\nSECRET_KEY_BASE=0f9c1e5a7b3d2c4e6f8a0b1c3d5e7f9a\n"), implode("\n", startPumaCrash()) . "\n");

    expect($e->getMessage())->toBe('coresmith-app-shop-web did not start, systemd reports it as activating. This Rails app runs with RAILS_ENV=development, but its gems were installed for production only. Set RAILS_ENV=production in its environment variables, then deploy again.');
});

test('the rails environment advice names the environment the app sets', function (string $value) {
    $advice = (new ProcessStartAdvice)->for(startRailsApp("RAILS_ENV={$value}\n"), "! Unable to load application: LoadError: cannot load such file -- debug/prelude\n*  Environment: production");

    expect($advice)->toBe("This Rails app runs with RAILS_ENV={$value}, but its gems were installed for production only. Set RAILS_ENV=production in its environment variables, then deploy again.");
})->with(['development', 'staging', 'Production']);

test('a rails app in production or with no RAILS_ENV of its own gets no environment advice for a file it cannot load', function (string $env) {
    expect((new ProcessStartAdvice)->for(startRailsApp($env), "*  Environment: production\n! Unable to load application: LoadError: cannot load such file -- stripe"))
        ->toBe('The process log says why. Fix it and deploy again, or roll back to the previous release.');
})->with([
    'production' => ["RAILS_ENV=production\n"],
    'not set' => ["SECRET_KEY_BASE=0f9c1e5a7b3d2c4e6f8a0b1c3d5e7f9a\n"],
    'set to nothing' => ["RAILS_ENV=\n"],
]);

test('a rails app outside production that fails for another reason gets the advice for that reason', function () {
    expect((new ProcessStartAdvice)->for(startRailsApp("RAILS_ENV=development\n"), 'Address already in use - bind(2) for "127.0.0.1" port 8100 (Errno::EADDRINUSE)'))
        ->toBe('Port 8100 is already taken by another process. Find it with ss -tlnp | grep 8100, stop it, then deploy again.');
});

test('advice is taken from the process log when the journal only says the process exited', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-queue'", 'coresmith-app-shop-queue.service: Main process exited, code=exited, status=1/FAILURE')
        ->respondTo("tail -n 200 '/var/coresmith/logs/shop/queue.log'", "PHP Parse error: syntax error, unexpected token \"}\" in /var/coresmith/apps/shop/current/app/Jobs/Send.php on line 12\n");

    try {
        startManager()->verifyStarted(startConnection($transport), startApp());
        $this->fail('A unit that was not running passed the start check.');
    } catch (ProcessDidNotStart $e) {
        expect($e->getMessage())->toContain('The code has a syntax error.')
            ->and($e->journal)->toContain('app/Jobs/Send.php on line 12');
    }
});

test('php-fpm is explained from its journal alone, since it has no log file of its own', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-fpm'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-fpm'", 'ERROR: failed to open configuration file');

    expect(fn() => startManager()->verifyStarted(startConnection($transport), startApp()))->toThrow(ProcessDidNotStart::class);

    expect(implode("\n", $transport->commands()))->not->toContain('tail -n');
});
