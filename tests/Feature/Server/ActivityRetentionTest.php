<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Spatie\Activitylog\Models\Activity;

function activityRetentionRow(string $description, int $daysAgo): Activity
{
    return Activity::query()->create([
        'log_name' => 'default',
        'description' => $description,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

test('server command records are pruned after their own window and other activity after the longer one', function () {
    activityRetentionRow('ssh_command_executed', 100);
    $recentCommand = activityRetentionRow('ssh_command_executed', 10);
    $olderDeploy = activityRetentionRow('deployment_started', 100);
    activityRetentionRow('deployment_started', 400);

    $this->artisan('coresmith:prune-activity')->assertSuccessful();

    expect(Activity::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recentCommand->id, $olderDeploy->id])->sort()->values()->all());
});

test('both windows can be set', function () {
    activityRetentionRow('ssh_command_executed', 6);
    $command = activityRetentionRow('ssh_command_executed', 4);
    activityRetentionRow('team_member_added', 31);
    $member = activityRetentionRow('team_member_added', 29);

    $this->artisan('coresmith:prune-activity', ['--ssh-days' => 5, '--days' => 30])->assertSuccessful();

    expect(Activity::query()->pluck('id')->sort()->values()->all())->toBe(collect([$command->id, $member->id])->sort()->values()->all());
});

test('a window under one day is refused and nothing is deleted', function () {
    activityRetentionRow('ssh_command_executed', 400);

    $this->artisan('coresmith:prune-activity', ['--ssh-days' => 0])
        ->expectsOutputToContain('Keep at least one day of activity.')
        ->assertFailed();

    expect(Activity::query()->count())->toBe(1);
});

test('activity is pruned every day', function () {
    $event = collect(app(Schedule::class)->events())->first(fn($event) => $event->description === 'coresmith:prune-activity');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *');
});
