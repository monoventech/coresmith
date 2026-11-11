<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\DeploymentStatus;
use App\Enums\Edition;
use App\Enums\ServerStatus;
use App\Exceptions\RemovalRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RemoveCoresmithRequest;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Address\InstallationAddress;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Install\Removal;
use App\Services\Server\ThisMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RemovalController extends Controller
{
    public function store(RemoveCoresmithRequest $request, Removal $removal): RedirectResponse
    {
        $refusal = $this->busy() ?? match (true) {
            !$removal->installed() => "Coresmith's removal helper isn't installed. Run the installer once, then try again: " . ThisMachine::INSTALLER,
            $removal->started() => 'Coresmith is already being removed.',
            default => null,
        };

        if ($refusal !== null) {
            return back()->withErrors(['removal' => $refusal]);
        }

        try {
            $removal->ping();
        } catch (RemovalRefused $e) {
            return back()->withErrors(['removal' => $e->getMessage()]);
        }

        activity()->causedBy($request->user())->log('coresmith_removal_started');

        $removal->start();

        return to_route('settings.removal.show');
    }

    public function show(Request $request, Removal $removal, InstallationAddress $address): Response
    {
        $this->authorizeRemoval($request);

        return Inertia::render('settings/Removing', [
            'address' => $address->host(),
            'health' => url('/up'),
            'status' => $removal->status(),
        ]);
    }

    public function status(Request $request, Removal $removal): JsonResponse
    {
        $this->authorizeRemoval($request);

        return response()->json($removal->status());
    }

    private function authorizeRemoval(Request $request): void
    {
        abort_if(Edition::current()->isCloud(), 404);
        abort_unless($request->user()->managesInstance(), 403);
    }

    private function busy(): ?string
    {
        $deployment = Deployment::query()
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->with('application')
            ->oldest()
            ->first();

        if ($deployment !== null) {
            return "A deploy is running for {$deployment->application?->name}. Wait for it to finish, then try again.";
        }

        $provisioning = Server::query()->where('status', ServerStatus::Provisioning->value)->oldest()->first();

        if ($provisioning !== null) {
            return "{$provisioning->name} is still provisioning. Wait for it to finish, then try again.";
        }

        foreach (Server::query()->whereNotNull('provider_task')->with('cloudProvider')->oldest()->get() as $server) {
            $task = CloudTask::of($server);

            if ($task !== null && $task->state === CloudTask::RUNNING) {
                $doing = match ($task->type) {
                    CloudTask::CREATE => 'creating',
                    CloudTask::RESIZE => 'resizing',
                    CloudTask::DESTROY => 'destroying',
                    default => 'working on',
                };

                return "Coresmith is still {$doing} {$server->name} at " . ($server->cloudProvider?->type->label() ?? 'its provider') . '. Wait for it to finish, then try again.';
            }
        }

        return null;
    }
}
