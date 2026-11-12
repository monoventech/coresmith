<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\Formats;
use Carbon\CarbonInterface;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{User, Application}
 */
function releaseCardApp(?string $live = '20261112091400'): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => $live, 'repository_branch' => 'main']);

    return [$user->fresh(), $application];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function releaseCardDeploy(Application $application, string $release, CarbonInterface $finished, array $attributes = []): Deployment
{
    return Deployment::factory()->for($application)->create([
        'release' => $release,
        'status' => DeploymentStatus::Succeeded,
        'started_at' => $finished->copy()->subMinutes(2),
        'finished_at' => $finished,
        ...$attributes,
    ]);
}

function releaseCardPage(User $user, Application $application): TestResponse
{
    return test()->actingAs($user)->get(route('servers.applications.show', [$application->server_id, $application]));
}

test('the current release card names the commit, its branch and author, and when it went live', function () {
    [$user, $application] = releaseCardApp();
    $finished = now()->subHours(2)->startOfMinute();
    releaseCardDeploy($application, '20261112091400', $finished, [
        'commit_hash' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
        'commit_message' => "Move invoices to the new queue\n\nThe old queue is retired.",
        'commit_author' => 'Maya Lindqvist',
        'branch' => 'release',
    ]);

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page->where('application.release', [
        'id' => '20261112-091400',
        'commit' => 'a1b2c3d',
        'message' => 'Move invoices to the new queue',
        'author' => 'Maya Lindqvist',
        'branch' => 'release',
        'when' => Formats::when($finished),
        'full' => Formats::absolute($finished),
    ]));
});

test('a release deployed without a commit author is credited to whoever deployed it', function () {
    [$user, $application] = releaseCardApp();
    releaseCardDeploy($application, '20261112091400', now()->subHour(), ['commit_author' => null, 'branch' => null, 'triggered_by' => $user->id]);

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page
        ->where('application.release.author', $user->name)
        ->where('application.release.branch', 'main'));
});

test('a live release with no deploy on record still shows its id and claims nothing else', function () {
    [$user, $application] = releaseCardApp();

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page->where('application.release', [
        'id' => '20261112-091400',
        'commit' => null,
        'message' => null,
        'author' => null,
        'branch' => null,
        'when' => null,
        'full' => null,
    ]));
});

test('an app with nothing live has no release and nothing to roll back to', function () {
    [$user, $application] = releaseCardApp(null);

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page
        ->where('application.release', null)
        ->where('application.rollback_href', null));
});

test('roll back opens the releases page on the previous successful release', function () {
    [$user, $application] = releaseCardApp();
    releaseCardDeploy($application, '20261111080000', now()->subDay());
    releaseCardDeploy($application, '20261112080000', now()->subHours(3), ['status' => DeploymentStatus::Failed]);
    releaseCardDeploy($application, '20261112091400', now()->subHours(2));

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page
        ->where('application.rollback_href', route('servers.applications.releases.index', [$application->server_id, $application, 'rollback' => '20261111080000'])));
});

test('an app whose only release is live has nothing to roll back to', function () {
    [$user, $application] = releaseCardApp();
    releaseCardDeploy($application, '20261112091400', now()->subHours(2));

    releaseCardPage($user, $application)->assertInertia(fn(AssertableInertia $page) => $page->where('application.rollback_href', null));
});
