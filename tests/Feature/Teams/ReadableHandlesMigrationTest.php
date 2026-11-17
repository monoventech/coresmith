<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

function readableHandlesMigration(): object
{
    return require base_path('database/migrations/2026_11_17_120000_readable_handles.php');
}

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test('the upgrade gives what already exists an address, oldest first, and skips removed apps', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web', 'created_at' => now()->subDays(5)]);
    $removed = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'created_at' => now()->subDays(4)]);
    $removed->delete();
    $older = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'created_at' => now()->subDays(3)]);
    $newer = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'created_at' => now()->subDays(2)]);
    $project = Project::factory()->for($team)->create(['name' => 'Cloud']);

    readableHandlesMigration()->down();
    readableHandlesMigration()->up();

    $handle = fn(string $table, string $id): ?string => DB::table($table)->where('id', $id)->value('handle');

    expect($handle('applications', $removed->id))->toBeNull()
        ->and($handle('applications', $older->id))->toBe('shop')
        ->and($handle('applications', $newer->id))->toBe('shop-2')
        ->and($handle('servers', $server->id))->toBe('web')
        ->and($handle('projects', $project->id))->toBe('cloud-2');
});
