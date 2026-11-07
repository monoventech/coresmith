<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Team;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Teams\TeamContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function currentTeam(): ?Team
    {
        return app(TeamContext::class)->team();
    }

    protected function destroyedServer(Server $server): RedirectResponse
    {
        $this->authorize('view', $server);

        $task = CloudTask::of($server);

        abort_unless($task?->type === CloudTask::DESTROY && $task->state === CloudTask::DONE, 404);

        $label = $server->cloudProvider()->withTrashed()->first()?->type->label() ?? 'its provider';

        return to_route('dashboard')->with('success', "Destroyed. {$server->name} was deleted at {$label}, and billing for it stopped.");
    }
}
