<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Services\Deployment\DatabaseFailure;

test('a PHP app whose migrations cannot reach its database is told which one to add and how to choose SQLite', function (string $output, string $engine) {
    expect(DatabaseFailure::reason($output, ApplicationType::Php))->toBe("Migrations couldn't connect to {$engine} where the app's settings point, so nothing was changed. Add a {$engine} database for the app on its server, or check its DB_ settings. To use SQLite instead, set DB_CONNECTION=sqlite.");
})->with([
    'MySQL refused' => ['SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: 127.0.0.1, Port: 3306)', 'MySQL'],
    'PostgreSQL refused' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused', 'PostgreSQL'],
]);

test('a Rails app whose migrations cannot reach its database is told which one to add and to set DATABASE_URL', function (string $output, string $engine) {
    expect(DatabaseFailure::reason($output, ApplicationType::Ruby))->toBe("Migrations couldn't connect to {$engine} where the app's settings point, so nothing was changed. Add a {$engine} database for the app on its server, then set DATABASE_URL in its environment to that database's URL.");
})->with([
    'PostgreSQL refused, railslink' => ['PG::ConnectionBad: connection to server at "127.0.0.1", port 5432 failed: Connection refused (PG::ConnectionBad)', 'PostgreSQL'],
    'MySQL refused' => ["Mysql2::Error::ConnectionError: Can't connect to MySQL server on '127.0.0.1:3306' (111)", 'MySQL'],
]);

test('a migration that fails for any other reason gets no database advice', function (ApplicationType $type) {
    expect(DatabaseFailure::reason('SQLSTATE[HY000]: General error: 1 near "OR": syntax error', $type))->toBeNull();
})->with([ApplicationType::Php, ApplicationType::Ruby]);
