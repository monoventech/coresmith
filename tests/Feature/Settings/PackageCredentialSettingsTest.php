<?php

declare(strict_types=1);

use App\Enums\PackageSource;
use App\Enums\TeamRole;
use App\Models\PackageCredential;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function packageSettingsOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function packageSettingsMember(Team $team, TeamRole $role): User
{
    $user = CloudBilling::member($team, $role);
    $user->forceFill(['current_team_id' => $team->getKey()])->save();

    return $user->fresh();
}

function packageSettingsForm(array $overrides = []): array
{
    return ['source' => 'composer', 'target' => 'nova.laravel.com', 'username' => 'ops@example.com', 'secret' => 'nova-license-key-9f2c', 'scope' => null, 'project_id' => null, ...$overrides];
}

test('an owner adds credentials, the secret is sealed and the audit log never sees it', function () {
    $team = Team::factory()->owned()->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->from(route('source-providers.index'))->post(route('package-credentials.store'), packageSettingsForm())
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Installs and builds use it from the next deploy.');

    $credential = PackageCredential::query()->sole();

    expect($credential->source)->toBe(PackageSource::Composer)
        ->and($credential->secret)->toBe('nova-license-key-9f2c')
        ->and($credential->created_by)->toBe($team->owner_id)
        ->and(json_encode(DB::table('package_credentials')->first()))->not->toContain('nova-license-key')
        ->and(json_encode(Activity::query()->where('description', 'package_credentials_added')->sole()->properties))->toContain('nova.laravel.com')->not->toContain('nova-license-key');
});

test('each source keeps only what it needs, normalised', function (array $input, array $expected) {
    $team = Team::factory()->owned()->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->post(route('package-credentials.store'), packageSettingsForm($input))->assertSessionHasNoErrors();

    expect(PackageCredential::query()->sole()->only(array_keys($expected)))->toBe($expected);
})->with([
    'npm registry and scopes' => [['source' => 'npm', 'target' => 'https://NPM.pkg.github.com', 'scope' => '@Acme, acme-ui', 'username' => 'ignored'], ['target' => 'https://npm.pkg.github.com/', 'scope' => '@acme @acme-ui', 'username' => null]],
    'composer host in capitals' => [['target' => 'Nova.Laravel.com'], ['target' => 'nova.laravel.com', 'scope' => null]],
    'git host and owner' => [['source' => 'git', 'target' => 'GitHub.com/Acme/', 'username' => ''], ['target' => 'github.com/Acme', 'username' => null]],
    'maven repository id' => [['source' => 'maven', 'target' => 'acme-releases', 'username' => 'deploy'], ['target' => 'acme-releases', 'username' => 'deploy']],
]);

test('credentials that cannot work are refused with what to fix', function (array $input, string $field, string $message) {
    $team = Team::factory()->owned()->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->post(route('package-credentials.store'), packageSettingsForm($input))->assertSessionHasErrors([$field => $message]);

    expect(PackageCredential::query()->count())->toBe(0);
})->with([
    'unknown source' => [['source' => 'cargo'], 'source', 'Pick where the packages come from.'],
    'host with a path' => [['target' => 'nova.laravel.com/packages'], 'target', 'Enter a host name, like nova.laravel.com.'],
    'host with a scheme' => [['target' => 'https://nova.laravel.com'], 'target', 'Enter a host name, like nova.laravel.com.'],
    'plain http registry' => [['source' => 'npm', 'target' => 'http://npm.acme.dev'], 'target', "Enter the registry's https:// address, like https://npm.pkg.github.com."],
    'registry with credentials' => [['source' => 'npm', 'target' => 'https://user:pass@npm.acme.dev'], 'target', "Enter the registry's https:// address, like https://npm.pkg.github.com."],
    'odd scope' => [['source' => 'npm', 'target' => 'https://npm.acme.dev', 'scope' => '@acme/ui'], 'scope', 'Scopes look like @acme. Separate several with commas.'],
    'maven id with spaces' => [['source' => 'maven', 'target' => 'acme releases'], 'target', 'Use the repository ID from pom.xml: letters, digits, dots, dashes and underscores.'],
    'git target with a scheme' => [['source' => 'git', 'target' => 'https://github.com/acme'], 'target', 'Enter a host, with an owner if you like, such as github.com/acme.'],
    'python without a username' => [['source' => 'python', 'target' => 'pypi.acme.dev', 'username' => ''], 'username', 'Enter the username.'],
    'maven without a username' => [['source' => 'maven', 'target' => 'acme', 'username' => null], 'username', 'Enter the username.'],
    'secret with a space' => [['secret' => 'two words'], 'secret', "A password or token can't contain spaces or line breaks."],
    'no secret' => [['secret' => ''], 'secret', 'Enter the password or token.'],
]);

test('the same host twice in one place is refused, though a project can have its own', function () {
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    PackageCredential::factory()->for($team)->create(['target' => 'nova.laravel.com']);
    $this->actingAs(packageSettingsOwner($team));

    $this->post(route('package-credentials.store'), packageSettingsForm(['target' => 'NOVA.laravel.com']))
        ->assertSessionHasErrors(['target' => 'Coresmith already has credentials for nova.laravel.com here. Replace those instead.']);

    $this->post(route('package-credentials.store'), packageSettingsForm(['project_id' => $project->id]))->assertSessionHasNoErrors();

    expect(PackageCredential::query()->where('project_id', $project->id)->count())->toBe(1);
});

test("another team's project is refused", function () {
    $team = Team::factory()->owned()->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->post(route('package-credentials.store'), packageSettingsForm(['project_id' => Project::factory()->create()->id]))
        ->assertSessionHasErrors(['project_id' => "Pick one of this team's projects."]);
});

test('a blank secret keeps the saved one when the rest changes', function () {
    $team = Team::factory()->owned()->create();
    $credential = PackageCredential::factory()->for($team)->create(['secret' => 'nova-license-key-9f2c']);
    $this->actingAs(packageSettingsOwner($team));

    $this->put(route('package-credentials.update', $credential), packageSettingsForm(['username' => 'billing@example.com', 'secret' => '']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Installs and builds use it from the next deploy.');

    expect($credential->fresh()->username)->toBe('billing@example.com')
        ->and($credential->fresh()->secret)->toBe('nova-license-key-9f2c');
});

test('an owner removes credentials', function () {
    $team = Team::factory()->owned()->create();
    $credential = PackageCredential::factory()->for($team)->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->delete(route('package-credentials.destroy', $credential))
        ->assertSessionHas('success', 'Removed. The next deploy installs without it.');

    expect(PackageCredential::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'package_credentials_removed')->count())->toBe(1);
});

test('developers and deployers cannot add, change or remove credentials', function (TeamRole $role) {
    $team = Team::factory()->owned()->create();
    $credential = PackageCredential::factory()->for($team)->create();
    $this->actingAs(packageSettingsMember($team, $role));

    $this->post(route('package-credentials.store'), packageSettingsForm(['target' => 'spark.laravel.com']))->assertForbidden();
    $this->put(route('package-credentials.update', $credential), packageSettingsForm())->assertForbidden();
    $this->delete(route('package-credentials.destroy', $credential))->assertForbidden();

    expect(PackageCredential::query()->count())->toBe(1);
})->with([TeamRole::Developer, TeamRole::Member]);

test("another team's credentials are not found", function () {
    $team = Team::factory()->owned()->create();
    $credential = PackageCredential::factory()->create();
    $this->actingAs(packageSettingsOwner($team));

    $this->put(route('package-credentials.update', $credential), packageSettingsForm())->assertNotFound();
    $this->delete(route('package-credentials.destroy', $credential))->assertNotFound();
});

test('the git settings list hosts for everyone and never a secret, and only managers see the hint and username', function (TeamRole $role, bool $manages) {
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    PackageCredential::factory()->for($team)->for($project)->npm('https://npm.pkg.github.com/', '@acme', 'ghp_npmtoken9f2c')->create();
    $user = $role === TeamRole::Owner ? packageSettingsOwner($team) : packageSettingsMember($team, $role);

    if (!$manages) {
        app(TeamAccess::class)->grant($team, $user, $project);
    }

    $response = $this->actingAs($user)->get(route('source-providers.index'))->assertOk();

    expect($response->getContent())->not->toContain('ghp_npmtoken9f2c');

    $response->assertInertia(fn(AssertableInertia $page) => $page
        ->where('packages.can_manage', $manages)
        ->where('packages.entries.0.source', 'npm')
        ->where('packages.entries.0.source_label', 'npm')
        ->where('packages.entries.0.target', 'https://npm.pkg.github.com/')
        ->where('packages.entries.0.scope', '@acme')
        ->where('packages.entries.0.project', 'Storefront')
        ->where('packages.entries.0.hint', $manages ? '9f2c' : null)
        ->where('packages.projects.0.name', 'Storefront')
        ->etc());
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, false],
]);

test("a developer sees the team-wide entries but not those of a project they can't see", function () {
    $team = Team::factory()->owned()->create();
    $hidden = Project::factory()->for($team)->create(['name' => 'Payroll']);
    PackageCredential::factory()->for($team)->create(['target' => 'nova.laravel.com']);
    PackageCredential::factory()->for($team)->for($hidden)->python()->create();

    $this->actingAs(packageSettingsMember($team, TeamRole::Developer))->get(route('source-providers.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->has('packages.entries', 1)
            ->where('packages.entries.0.target', 'nova.laravel.com')
            ->has('packages.projects', 0)
            ->etc());
});
