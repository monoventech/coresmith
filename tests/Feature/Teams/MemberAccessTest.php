<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Teams\TeamAccess;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    config(['mail.default' => 'smtp']);
});

function memberAccessPerson(Team $team, TeamRole $role, bool $everything = true, string $name = 'Priya Nair'): User
{
    $user = User::factory()->create(['name' => $name]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array{team: Team, owner: User, member: User, web: Server, db: Server, cache: Server, shop: Application, api: Application}
 */
function memberAccessTeam(): array
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $db = Server::factory()->for($team)->create(['name' => 'db']);
    $cache = Server::factory()->for($team)->create(['name' => 'cache']);

    return [
        'team' => $team,
        'owner' => memberAccessPerson($team, TeamRole::Owner, name: 'Maya Lindqvist'),
        'member' => memberAccessPerson($team, TeamRole::Member),
        'web' => $web,
        'db' => $db,
        'cache' => $cache,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop']),
        'api' => Application::factory()->for($team)->for($cache)->create(['name' => 'api']),
    ];
}

/**
 * @param  array<string, mixed>  $access
 */
function memberAccessSave(User $as, Team $team, User $member, array $access): TestResponse
{
    return test()->actingAs($as)->from(route('teams.index'))->put(route('teams.members.access.update', [$team, $member]), $access);
}

function memberAccessInvite(User $as, Team $team, string $email, ?string $access): TestResponse
{
    return test()->actingAs($as)->from(route('teams.index'))->post(route('teams.members.store', $team), array_filter([
        'email' => $email,
        'role' => TeamRole::Member->value,
        'access' => $access,
    ]));
}

function memberAccessInviteToken(string $email): string
{
    $token = '';

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }

        $token = Str::afterLast((string) $notification->toMail($notifiable)->actionUrl, '/');

        return true;
    });

    return $token;
}

test('an owner narrows a member to two servers and an app', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member, 'web' => $web, 'db' => $db, 'api' => $api] = memberAccessTeam();

    memberAccessSave($owner, $team, $member, ['everything' => false, 'servers' => [$web->id, $db->id], 'applications' => [$api->id]])
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('success', 'Saved.');

    $summary = app(TeamAccess::class)->summary($team, $member);
    $logged = Activity::query()->where('description', 'member_access_changed')->sole();

    expect($summary['everything'])->toBeFalse()
        ->and($summary['servers'])->toEqualCanonicalizing([$web->id, $db->id])
        ->and($summary['applications'])->toBe([$api->id])
        ->and($logged->causer_id)->toBe($owner->id)
        ->and($logged->properties->only(['email', 'everything'])->all())->toBe(['email' => $member->email, 'everything' => false])
        ->and($logged->properties['servers'])->toEqualCanonicalizing([$web->id, $db->id])
        ->and($logged->properties['applications'])->toBe([$api->id]);
});

test('an owner gives a narrowed member everything again', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member, 'web' => $web] = memberAccessTeam();
    app(TeamAccess::class)->sync($team, $member, false, [$web->id], []);

    memberAccessSave($owner, $team, $member, ['everything' => true, 'servers' => [$web->id], 'applications' => []])->assertSessionHas('success', 'Saved.');

    expect(app(TeamAccess::class)->seesEverything($member, $team))->toBeTrue();
});

test('removing someone takes what was shared with them, so being invited back starts from nothing', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member, 'web' => $web, 'api' => $api] = memberAccessTeam();
    app(TeamAccess::class)->sync($team, $member, false, [$web->id], [$api->id]);

    $this->actingAs($owner)->delete(route('teams.members.destroy', [$team, $member]))->assertSessionHas('success', 'Member removed.');

    expect(AccessGrant::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

test('the team page shows owners what each member sees and everything there is to share', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member, 'web' => $web, 'db' => $db, 'cache' => $cache, 'shop' => $shop, 'api' => $api] = memberAccessTeam();
    app(TeamAccess::class)->sync($team, $member, false, [$web->id], []);

    $this->actingAs($owner)->get(route('teams.index'))->assertInertia(fn(AssertableInertia $page) => $page
        ->where('can.shareAccess', true)
        ->where('members', fn($members): bool => collect($members)->firstWhere('id', $member->id)['access'] === ['everything' => false, 'servers' => [$web->id], 'applications' => [], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []]
            && collect($members)->firstWhere('id', $owner->id)['access'] === null)
        ->where('shareable', ['servers' => [
            ['id' => $cache->id, 'name' => 'cache', 'this_machine' => false, 'applications' => [['id' => $api->id, 'name' => 'api']], 'databases' => []],
            ['id' => $db->id, 'name' => 'db', 'this_machine' => false, 'applications' => [], 'databases' => []],
            ['id' => $web->id, 'name' => 'web', 'this_machine' => false, 'applications' => [['id' => $shop->id, 'name' => 'shop']], 'databases' => []],
        ], 'projects' => []]));
});

test('an admin sees nobody\'s access and has nothing to share', function () {
    ['team' => $team] = memberAccessTeam();
    $admin = memberAccessPerson($team, TeamRole::Admin, name: 'Ada Admin');

    $this->actingAs($admin)->get(route('teams.index'))->assertInertia(fn(AssertableInertia $page) => $page
        ->where('can.shareAccess', false)
        ->where('shareable', null)
        ->where('members', fn($members): bool => collect($members)->every(fn(array $member): bool => $member['access'] === null)));
});

test('an admin cannot share access', function () {
    ['team' => $team, 'member' => $member, 'web' => $web] = memberAccessTeam();
    $admin = memberAccessPerson($team, TeamRole::Admin, name: 'Ada Admin');

    memberAccessSave($admin, $team, $member, ['everything' => false, 'servers' => [$web->id], 'applications' => []])->assertForbidden();

    expect(app(TeamAccess::class)->seesEverything($member, $team))->toBeTrue();
});

test("an owner's access cannot be narrowed", function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();
    $second = memberAccessPerson($team, TeamRole::Owner, name: 'Sam Second');

    memberAccessSave($owner, $team, $second, ['everything' => false, 'servers' => [], 'applications' => []])->assertForbidden();
    memberAccessSave($owner, $team, $owner, ['everything' => false, 'servers' => [], 'applications' => []])->assertForbidden();

    expect(Activity::query()->where('description', 'member_access_changed')->exists())->toBeFalse();
});

test('someone outside the team has no access to change', function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();

    memberAccessSave($owner, $team, User::factory()->create(), ['everything' => false, 'servers' => [], 'applications' => []])->assertNotFound();
});

test('ids from another team are refused next to the field', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member] = memberAccessTeam();
    $other = Team::factory()->create();
    $theirs = Server::factory()->for($other)->create();
    $theirApp = Application::factory()->for($other)->for($theirs)->create();

    memberAccessSave($owner, $team, $member, ['everything' => false, 'servers' => [$theirs->id], 'applications' => [$theirApp->id]])
        ->assertSessionHasErrors([
            'servers' => "One of those servers isn't in Acme any more. Reload the page and choose again.",
            'applications' => "One of those apps isn't in Acme any more. Reload the page and choose again.",
        ]);

    expect(app(TeamAccess::class)->seesEverything($member, $team))->toBeTrue();
});

test('an invitation to only what I share narrows the member when they accept', function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();

    memberAccessInvite($owner, $team, 'newcomer@example.test', 'shared')->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->sole()->sees_everything)->toBeFalse();

    $newcomer = User::factory()->create(['email' => 'newcomer@example.test']);

    $this->actingAs($newcomer)->post(route('invitations.accept', memberAccessInviteToken('newcomer@example.test')))->assertRedirect();

    expect(app(TeamAccess::class)->summary($team, $newcomer->fresh()))->toBe(['everything' => false, 'servers' => [], 'applications' => [], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []]);
});

test('an invitation from an owner who picks nothing sees everything', function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();

    memberAccessInvite($owner, $team, 'newcomer@example.test', null)->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->sole()->sees_everything)->toBeTrue();
});

test('someone with an account here who joins at once with only what I share starts with nothing shared', function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();
    $existing = User::factory()->create(['email' => 'existing@example.test']);

    memberAccessInvite($owner, $team, 'existing@example.test', 'shared')->assertSessionHas('success', 'Member added.');

    expect(app(TeamAccess::class)->seesEverything($existing, $team))->toBeFalse();
});

test("an admin's invitee starts with what the admin sees, whatever the form says", function (bool $adminSeesEverything) {
    ['team' => $team] = memberAccessTeam();
    $admin = memberAccessPerson($team, TeamRole::Admin, $adminSeesEverything, 'Ada Admin');

    memberAccessInvite($admin, $team, 'newcomer@example.test', 'everything')->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->sole()->sees_everything)->toBe($adminSeesEverything);
})->with(['an admin who sees everything' => [true], 'a narrowed admin' => [false]]);

test('the team page offers each project and its environments to share', function () {
    ['team' => $team, 'owner' => $owner] = memberAccessTeam();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();

    $this->actingAs($owner)->get(route('teams.index'))->assertInertia(fn(AssertableInertia $page) => $page
        ->where('shareable.projects', [['id' => $project->id, 'name' => 'Storefront', 'environments' => [
            ['id' => $production->id, 'name' => 'Production'],
            ['id' => $staging->id, 'name' => 'Staging'],
        ]]]));
});

test('an owner shares one environment of a project with a member', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member] = memberAccessTeam();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    $staging = $project->environments()->where('name', 'Staging')->sole();

    memberAccessSave($owner, $team, $member, ['everything' => false, 'environments' => [$staging->id], 'withheld_applications' => [], 'withheld_databases' => []])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    expect(app(TeamAccess::class)->summary($team, $member))->toBe(['everything' => false, 'servers' => [], 'applications' => [], 'projects' => [], 'environments' => [$staging->id], 'withheld_applications' => [], 'withheld_databases' => []])
        ->and(Activity::query()->where('description', 'member_access_changed')->sole()->properties['environments'])->toBe([$staging->id]);

    memberAccessSave($owner, $team, $member, ['everything' => false, 'projects' => [$project->id]])->assertSessionHasNoErrors();

    expect(app(TeamAccess::class)->summary($team, $member)['projects'])->toBe([$project->id]);
});

test('a project or environment from another team is refused', function () {
    ['team' => $team, 'owner' => $owner, 'member' => $member] = memberAccessTeam();
    $theirs = Project::factory()->withEnvironments(['Production'])->create();

    memberAccessSave($owner, $team, $member, ['everything' => false, 'projects' => [$theirs->id]])
        ->assertSessionHasErrors(['projects' => "One of those projects isn't in Acme any more. Reload the page and choose again."]);
    memberAccessSave($owner, $team, $member, ['everything' => false, 'environments' => [$theirs->environments()->sole()->id], 'withheld_applications' => [], 'withheld_databases' => []])
        ->assertSessionHasErrors(['environments' => "One of those environments isn't in Acme any more. Reload the page and choose again."]);

    expect(app(TeamAccess::class)->seesEverything($member, $team))->toBeTrue();
});
