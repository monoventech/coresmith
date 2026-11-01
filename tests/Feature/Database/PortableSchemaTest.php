<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\User;
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
