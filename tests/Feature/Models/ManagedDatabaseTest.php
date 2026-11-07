<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\Panel\FleetSnapshot;
use Illuminate\Support\Facades\DB;

test('a managed database uses the databases table', function () {
    expect((new ManagedDatabase)->getTable())->toBe('databases');
});

test('the database password is encrypted at rest', function () {
    $database = ManagedDatabase::factory()->create(['db_password' => 'hunter2']);

    expect(DB::table('databases')->where('id', $database->id)->value('db_password'))->not->toContain('hunter2')
        ->and($database->fresh()->db_password)->toBe('hunter2');
});

test('the database type casts to an enum', function () {
    expect(ManagedDatabase::factory()->create(['type' => DatabaseType::Postgresql])->fresh()->type)
        ->toBe(DatabaseType::Postgresql);
});

test('a server has many metrics', function () {
    $server = Server::factory()->create();
    ServerMetric::factory()->count(3)->for($server)->create();

    expect($server->metrics)->toHaveCount(3);
});

test('server metrics use an auto-increment id', function () {
    expect(ServerMetric::factory()->create()->id)->toBeInt();
});

test('the metric factory never draws a memory or disk reading on an alert line', function () {
    $readings = ServerMetric::factory()->count(400)->make(['server_id' => 'none']);

    expect($readings->max('memory_percent'))->toBeLessThan(FleetSnapshot::MEMORY_WARNING)
        ->and($readings->max('disk_percent'))->toBeLessThan(FleetSnapshot::DISK_WARNING);
});
