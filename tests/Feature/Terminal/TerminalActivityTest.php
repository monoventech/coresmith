<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Panel\ActivityFeed;

test("terminal entries read in plain words on the server's Activity", function () {
    $server = Server::factory()->create(['name' => 'web-1']);
    activity()->performedOn($server)->withProperties(['mode' => 'root', 'app' => null, 'by' => 'Ada'])->log('terminal_opened');
    activity()->performedOn($server)->withProperties(['mode' => 'app', 'app' => 'shop', 'by' => 'Ada'])->log('terminal_opened');
    activity()->performedOn($server)->withProperties(['mode' => 'root', 'reason' => 'idle', 'minutes' => 32, 'by' => 'Ada'])->log('terminal_ended');
    activity()->performedOn($server)->withProperties(['mode' => 'root', 'reason' => 'ended', 'minutes' => 0, 'by' => 'Ada'])->log('terminal_ended');
    activity()->performedOn($server)->withProperties(['mode' => 'root', 'reason' => 'failed', 'minutes' => 0, 'by' => 'Ada'])->log('terminal_ended');
    activity()->performedOn($server)->withProperties(['changes' => ['The firewall rules', "The web server's settings"], 'by' => 'Ada'])->log('terminal_changed');

    $sentences = collect(app(ActivityFeed::class)->forServer($server))->map(fn(array $row): string => implode('', array_column($row['parts'], 'text')))->all();

    expect($sentences)->toContain('Root terminal opened on web-1, by Ada')
        ->toContain("Terminal opened as shop's user on web-1, by Ada")
        ->toContain("Ada's terminal on web-1 ended after 32 minutes, 10 minutes after the last typing")
        ->toContain("Ada's terminal on web-1 ended after less than a minute")
        ->toContain("A terminal couldn't open on web-1")
        ->toContain("A terminal session on web-1 changed the firewall rules and the web server's settings, by Ada");
});
