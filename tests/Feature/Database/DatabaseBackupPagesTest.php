<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Database\Backup;
use App\Services\Database\BackupListing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function backupPagesOwner(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

function backupPagesListed(ManagedDatabase $database, int $count): void
{
    $backups = array_map(function (int $hoursAgo): Backup {
        $at = CarbonImmutable::parse('2026-09-12 23:00:00')->subHours($hoursAgo);

        return new Backup($at->format('YmdHis'), $at, 2048, path: '/var/backups/coresmith/' . $at->format('YmdHis') . '.sql.gz');
    }, range(0, $count - 1));

    Cache::put('database-backups:' . $database->getKey(), new BackupListing($backups), 600);
}

beforeEach(fn() => Queue::fake());

test('backups come ten to a page, newest first, and their links keep you on the database', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Postgresql]);
    backupPagesListed($database, 23);
    $owner = backupPagesOwner($team);

    $this->actingAs($owner)
        ->get(route('servers.databases.show', [$server, $database]))
        ->assertInertia(fn($page) => $page
            ->has('backups.rows', 10)
            ->where('backups.rows.0.stamp', '20260912230000')
            ->where('backups.pages.total', 23)
            ->where('backups.pages.older', route('servers.databases.show', [$server, $database, 'backups_page' => 2])));

    $this->actingAs($owner)
        ->get(route('servers.databases.show', [$server, $database, 'backups_page' => 3]))
        ->assertInertia(fn($page) => $page
            ->has('backups.rows', 3)
            ->where('backups.rows.0.stamp', '20260912030000')
            ->where('backups.pages.from', 21)
            ->where('backups.pages.to', 23)
            ->where('backups.pages.newer', route('servers.databases.show', [$server, $database, 'backups_page' => 2]))
            ->where('backups.pages.older', null));
});

test('a backups page past the end shows the last page', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Postgresql]);
    backupPagesListed($database, 12);

    $this->actingAs(backupPagesOwner($team))
        ->get(route('servers.databases.show', [$server, $database, 'backups_page' => 9]))
        ->assertInertia(fn($page) => $page->has('backups.rows', 2)->where('backups.pages.current', 2));
});
