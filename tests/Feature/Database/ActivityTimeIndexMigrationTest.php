<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function activityTimeIndexMigration(): object
{
    return require base_path('database/migrations/2026_11_27_120000_index_activity_log_by_time.php');
}

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test('activity is indexed by time for the newest-first audit log, and the index comes off again', function () {
    expect(Schema::hasIndex('activity_log', ['created_at']))->toBeTrue();

    activityTimeIndexMigration()->down();

    expect(Schema::hasIndex('activity_log', ['created_at']))->toBeFalse();

    activityTimeIndexMigration()->up();

    expect(Schema::hasIndex('activity_log', ['created_at']))->toBeTrue();
});
