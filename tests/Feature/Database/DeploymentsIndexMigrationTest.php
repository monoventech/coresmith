<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function deploymentsIndexMigration(): object
{
    return require base_path('database/migrations/2026_11_25_100000_index_deployments_by_application_and_time.php');
}

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test("deploys are indexed by app and time, and the index comes off again with the app's foreign key kept", function () {
    expect(Schema::hasIndex('deployments', ['application_id', 'created_at']))->toBeTrue();

    deploymentsIndexMigration()->down();

    expect(Schema::hasIndex('deployments', ['application_id', 'created_at']))->toBeFalse()
        ->and(collect(Schema::getForeignKeys('deployments'))->pluck('columns')->all())->toContain(['application_id']);

    deploymentsIndexMigration()->up();

    expect(Schema::hasIndex('deployments', ['application_id', 'created_at']))->toBeTrue();
});
