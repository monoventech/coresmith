<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function withoutEnvironmentsMigration(): object
{
    return require base_path('database/migrations/2026_11_16_200000_projects_without_environments.php');
}

function withoutEnvironmentsEnvironment(Project $project, string $name, int $position = 0): string
{
    $id = (string) Str::ulid();

    DB::table('environments')->insert(['id' => $id, 'project_id' => $project->getKey(), 'name' => $name, 'position' => $position, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function withoutEnvironmentsFile(Application|ManagedDatabase $subject, string $environment): void
{
    DB::table($subject->getTable())->where('id', $subject->getKey())->update(['environment_id' => $environment]);
}

function withoutEnvironmentsGrant(Team $team, User $user, array $columns): void
{
    DB::table('access_grants')->insert(['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'withheld' => false, 'created_at' => now(), 'updated_at' => now(), ...$columns]);
}

function withoutEnvironmentsMember(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);

    return $user->fresh();
}

/**
 * @return array{team: Team, web: Server, project: Project, production: string, staging: string, shop: Application, preview: Application, ledger: ManagedDatabase, scratch: ManagedDatabase}
 */
function withoutEnvironmentsStorefront(): array
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $production = withoutEnvironmentsEnvironment($project, 'Production');
    $staging = withoutEnvironmentsEnvironment($project, 'Staging', 1);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $preview = Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $ledger = ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'ledger']);
    $scratch = ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'scratch']);

    withoutEnvironmentsFile($shop, $production);
    withoutEnvironmentsFile($preview, $staging);
    withoutEnvironmentsFile($ledger, $production);
    withoutEnvironmentsFile($scratch, $staging);

    return compact('team', 'web', 'project', 'production', 'staging', 'shop', 'preview', 'ledger', 'scratch');
}

beforeEach(fn() => withoutEnvironmentsMigration()->down());

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test('apps and databases join the project their environment was in, removed apps included, and environments are gone', function () {
    $storefront = withoutEnvironmentsStorefront();
    $loose = Application::factory()->for($storefront['team'])->for($storefront['web'])->create();
    DB::table('applications')->where('id', $storefront['preview']->id)->update(['deleted_at' => now()]);

    withoutEnvironmentsMigration()->up();

    expect(Application::find($storefront['shop']->id)->project_id)->toBe($storefront['project']->id)
        ->and(Application::withTrashed()->find($storefront['preview']->id)->project_id)->toBe($storefront['project']->id)
        ->and(Application::find($loose->id)->project_id)->toBeNull()
        ->and(ManagedDatabase::find($storefront['ledger']->id)->project_id)->toBe($storefront['project']->id)
        ->and(ManagedDatabase::find($storefront['scratch']->id)->project_id)->toBe($storefront['project']->id)
        ->and(Schema::hasTable('environments'))->toBeFalse()
        ->and(Schema::hasColumn('applications', 'environment_id'))->toBeFalse()
        ->and(Schema::hasColumn('databases', 'environment_id'))->toBeFalse()
        ->and(Schema::hasColumn('access_grants', 'environment_id'))->toBeFalse()
        ->and(Schema::hasColumn('servers', 'environment'))->toBeFalse();
});

test('apps take their environment\'s name as a tag, unless their project only ever had its first Production', function () {
    $storefront = withoutEnvironmentsStorefront();
    $quests = Project::factory()->for($storefront['team'])->create(['name' => 'Side quests']);
    $blog = Project::factory()->for($storefront['team'])->create(['name' => 'Blog']);
    $miagraph = Application::factory()->for($storefront['team'])->for($storefront['web'])->create(['name' => 'miagraph']);
    $journal = Application::factory()->for($storefront['team'])->for($storefront['web'])->create(['name' => 'journal']);
    withoutEnvironmentsFile($miagraph, withoutEnvironmentsEnvironment($quests, 'Production'));
    withoutEnvironmentsFile($journal, withoutEnvironmentsEnvironment($blog, 'Live'));

    withoutEnvironmentsMigration()->up();

    expect(Application::find($storefront['shop']->id)->tags)->toBe(['Production'])
        ->and(Application::find($storefront['preview']->id)->tags)->toBe(['Staging'])
        ->and(Application::find($miagraph->id)->tags)->toBe([])
        ->and(Application::find($miagraph->id)->project_id)->toBe($quests->id)
        ->and(Application::find($journal->id)->tags)->toBe(['Live']);
});

test('a member who had every environment of a project now has the project', function () {
    $storefront = withoutEnvironmentsStorefront();
    $member = withoutEnvironmentsMember($storefront['team']);
    withoutEnvironmentsGrant($storefront['team'], $member, ['environment_id' => $storefront['production']]);
    withoutEnvironmentsGrant($storefront['team'], $member, ['environment_id' => $storefront['staging']]);

    withoutEnvironmentsMigration()->up();

    $access = app(TeamAccess::class);

    expect(AccessGrant::query()->where('user_id', $member->id)->whereNotNull('project_id')->pluck('project_id')->all())->toBe([$storefront['project']->id])
        ->and(AccessGrant::query()->where('user_id', $member->id)->where(fn($query) => $query->whereNotNull('application_id')->orWhereNotNull('database_id'))->count())->toBe(0)
        ->and($access->canSeeApplication($member, Application::find($storefront['shop']->id)))->toBeTrue()
        ->and($access->canSeeDatabase($member, ManagedDatabase::find($storefront['scratch']->id)))->toBeTrue();
});

test('a member who had some environments of a project now has exactly their apps and databases, and nothing more', function () {
    $storefront = withoutEnvironmentsStorefront();
    $member = withoutEnvironmentsMember($storefront['team']);
    withoutEnvironmentsGrant($storefront['team'], $member, ['environment_id' => $storefront['staging']]);

    withoutEnvironmentsMigration()->up();

    $access = app(TeamAccess::class);
    $grants = AccessGrant::query()->where('user_id', $member->id)->where('withheld', false);

    expect((clone $grants)->whereNotNull('application_id')->pluck('application_id')->all())->toBe([$storefront['preview']->id])
        ->and((clone $grants)->whereNotNull('database_id')->pluck('database_id')->all())->toBe([$storefront['scratch']->id])
        ->and((clone $grants)->whereNotNull('project_id')->count())->toBe(0)
        ->and($access->canSeeApplication($member, Application::find($storefront['preview']->id)))->toBeTrue()
        ->and($access->canSeeDatabase($member, ManagedDatabase::find($storefront['scratch']->id)))->toBeTrue()
        ->and($access->canSeeApplication($member, Application::find($storefront['shop']->id)))->toBeFalse()
        ->and($access->canSeeDatabase($member, ManagedDatabase::find($storefront['ledger']->id)))->toBeFalse()
        ->and($access->canSeeProject($member, $storefront['project']))->toBeFalse();
});

test('an app held back under a shared server stays visible when an environment showed it', function () {
    $storefront = withoutEnvironmentsStorefront();
    $member = withoutEnvironmentsMember($storefront['team']);
    withoutEnvironmentsGrant($storefront['team'], $member, ['server_id' => $storefront['web']->id]);
    withoutEnvironmentsGrant($storefront['team'], $member, ['application_id' => $storefront['preview']->id, 'withheld' => true]);
    withoutEnvironmentsGrant($storefront['team'], $member, ['database_id' => $storefront['scratch']->id, 'withheld' => true]);
    withoutEnvironmentsGrant($storefront['team'], $member, ['environment_id' => $storefront['staging']]);

    withoutEnvironmentsMigration()->up();

    $access = app(TeamAccess::class);

    expect(AccessGrant::query()->where('user_id', $member->id)->where('application_id', $storefront['preview']->id)->sole()->withheld)->toBeFalse()
        ->and(AccessGrant::query()->where('user_id', $member->id)->where('database_id', $storefront['scratch']->id)->sole()->withheld)->toBeFalse()
        ->and($access->canSeeApplication($member, Application::find($storefront['preview']->id)))->toBeTrue()
        ->and($access->canSeeDatabase($member, ManagedDatabase::find($storefront['scratch']->id)))->toBeTrue();
});

test('the migration reverses to environments and runs again', function () {
    withoutEnvironmentsMigration()->up();
    withoutEnvironmentsMigration()->down();

    expect(Schema::hasTable('environments'))->toBeTrue()
        ->and(Schema::hasColumn('applications', 'environment_id'))->toBeTrue()
        ->and(Schema::hasColumn('servers', 'environment'))->toBeTrue()
        ->and(Schema::hasColumn('applications', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('applications', 'tags'))->toBeFalse();

    withoutEnvironmentsMigration()->up();

    expect(Schema::hasColumns('applications', ['project_id', 'tags']))->toBeTrue()
        ->and(Schema::hasColumn('databases', 'project_id'))->toBeTrue();
});
