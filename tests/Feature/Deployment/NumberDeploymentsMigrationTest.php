<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

function numberDeploymentsMigration(): object
{
    return require base_path('database/migrations/2026_11_18_200000_number_deployments.php');
}

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test('the upgrade numbers each app\'s deploys oldest first and the next deploy follows on', function () {
    $shop = Application::factory()->create();
    $blog = Application::factory()->create();
    $later = Deployment::factory()->for($shop)->create(['created_at' => now()->subHour()]);
    $earlier = Deployment::factory()->for($shop)->create(['created_at' => now()->subDay()]);
    $only = Deployment::factory()->for($blog)->create(['created_at' => now()->subMinutes(5)]);

    numberDeploymentsMigration()->down();
    numberDeploymentsMigration()->up();

    $number = fn(Deployment $deployment): ?int => DB::table('deployments')->where('id', $deployment->id)->value('number');

    expect([$number($earlier), $number($later), $number($only)])->toBe([1, 2, 1])
        ->and(Deployment::factory()->for($shop->fresh())->create()->number)->toBe(3)
        ->and(Deployment::factory()->for($blog->fresh())->create()->number)->toBe(2);
});
