<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('morph keys hold ulids', function (string $table, string $column) {
    expect(Schema::getColumnType($table, $column))->toBe('varchar');
})->with([
    'activity subject' => ['activity_log', 'subject_id'],
    'activity causer' => ['activity_log', 'causer_id'],
    'token owner' => ['personal_access_tokens', 'tokenable_id'],
]);

test('an activity entry keeps the ulids it was given', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create();

    activity()->performedOn($server)->causedBy($user)->log('portable_schema_checked');

    $row = DB::table('activity_log')->where('description', 'portable_schema_checked')->first();

    expect($row->subject_id)->toBe($server->id)->and($row->causer_id)->toBe($user->id);
});

test('a forty character session id fits', function () {
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'payload' => '', 'last_activity' => time()]);

    expect(DB::table('sessions')->where('id', str_repeat('s', 40))->exists())->toBeTrue()
        ->and(Schema::getColumnType('sessions', 'id'))->toBe('varchar');
});

test('a database that is not sqlite gets no sqlite file named after it', function () {
    $name = 'coresmith_portable_' . bin2hex(random_bytes(4));
    $default = config('database.default');
    $migration = require database_path('migrations/0000_00_00_000000_optimize_database_settings.php');

    config(['database.default' => 'pgsql', 'database.connections.sqlite.database' => base_path($name)]);

    try {
        $migration->up();
    } finally {
        config(['database.default' => $default]);
    }

    $created = file_exists(base_path($name));
    @unlink(base_path($name));

    expect($created)->toBeFalse();
});

test('a time in the hour the clocks skip in spring is kept as it was written', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-28 01:30:00'));

    $server = Server::factory()->create();

    expect($server->fresh()->created_at?->toIso8601ZuluString())->toBe('2027-03-28T01:30:00Z');
});

test('the database reads and writes times in utc whatever zone its own server keeps', function () {
    if (!Schema::getConnection() instanceof MySqlConnection) {
        $this->markTestSkipped('Only MySQL and MariaDB convert timestamps through the session time zone; SQLite stores text and PostgreSQL keeps a timestamp without time zone as written.');
    }

    expect(DB::selectOne('select @@session.time_zone as zone')->zone)->toBe('+00:00');
});

test('the unique indexes the panel relies on survive every migration', function () {
    $unique = fn(string $table): array => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->all();

    expect($unique('sso_identities'))->toContain(['issuer', 'subject'])
        ->and($unique('users'))->toContain(['email'])
        ->and($unique('team_invitations'))->toContain(['team_id', 'email'])
        ->and($unique('password_reset_tokens'))->toContain(['email']);
});

test('on mysql, identities and email addresses compare byte for byte and long text has room', function () {
    $connection = Schema::getConnection();

    if (!$connection instanceof MySqlConnection) {
        $this->markTestSkipped('Only MySQL and MariaDB get binary collations and LONGTEXT; SQLite and PostgreSQL keep these columns as created.');
    }

    $binary = $connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
    $column = fn(string $table, string $name): array => collect(Schema::getColumns($table))->firstWhere('name', $name) ?? [];

    expect($column('sso_identities', 'issuer')['collation'] ?? null)->toBe($binary)
        ->and($column('sso_identities', 'subject')['collation'] ?? null)->toBe($binary)
        ->and($column('users', 'email')['collation'] ?? null)->toBe($binary)
        ->and($column('team_invitations', 'email')['collation'] ?? null)->toBe($binary)
        ->and($column('password_reset_tokens', 'email')['collation'] ?? null)->toBe($binary)
        ->and($column('deployments', 'log')['type_name'] ?? null)->toBe('longtext')
        ->and($column('applications', 'env_variables')['type_name'] ?? null)->toBe('longtext');
});
