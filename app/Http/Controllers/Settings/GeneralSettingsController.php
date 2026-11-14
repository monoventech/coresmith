<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Edition;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Jobs\BackUpPanel;
use App\Models\InstanceSetting;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Moves\MovePresenter;
use App\Services\Panel\AddressPresenter;
use App\Services\Panel\PanelBackup;
use App\Services\Panel\PanelUpdatesPresenter;
use App\Services\Panel\RemovalPresenter;
use App\Services\Teams\AccountClosing;
use App\Services\Teams\TeamDeletion;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class GeneralSettingsController extends Controller
{
    public function backup(Request $request): RedirectResponse
    {
        abort_unless($this->managesInstance($request), 403);

        BackUpPanel::dispatch();

        return back()->with('success', 'Backing up. The database and environment are archived, the last ' . PanelBackup::KEEP . ' are kept.');
    }

    public function show(Request $request, PanelBackup $backups, PanelUpdatesPresenter $updates, AccountClosing $closing, TeamDeletion $deletion, MovePresenter $moves, AddressPresenter $address, RemovalPresenter $removal): Response
    {
        $cloud = Edition::current()->isCloud();
        $installed = $cloud ? null : InstanceSetting::get('installed_at');
        $latest = $cloud ? null : $backups->latest();

        return Inertia::render('settings/General', [
            'instance' => [
                'name' => InstanceSetting::get('instance_name', config('app.name')),
                'version' => (string) config('coresmith.version', '0.1.0'),
                'installed' => is_string($installed) ? CarbonImmutable::parse($installed)->format('M j, Y') : null,
                'timezone' => InstanceSetting::get('timezone', config('app.timezone')),
                'notification_email' => $cloud ? null : InstanceSetting::get('notification_email'),
            ],
            'address' => $address->present(),
            'backups' => [
                'last' => $latest === null ? null : [
                    'when' => $latest['at']->isToday() ? 'today ' . $latest['at']->format('H:i') : $latest['at']->format('M j, H:i'),
                    'size' => Number::fileSize($latest['bytes'], precision: 1),
                    'verified' => $latest['verified'],
                ],
                'keep' => PanelBackup::KEEP,
            ],
            'account' => ['refusal' => $closing->refusal($request->user()), 'hidden' => $closing->hidden($request->user())],
            'removal' => $removal->present($request->user(), $this->currentTeam()),
            'team_deletion' => $this->teamDeletion($request, $this->currentTeam(), $deletion),
            'timezones' => DateTimeZone::listIdentifiers(),
            'keys' => $request->user()->sshKeys()->latest()->get()->map(fn(SshKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'type' => $this->type($key->public_key),
                'fingerprint' => $key->fingerprint,
                'added' => $key->created_at?->format('M Y'),
            ])->values(),
            'updates' => $updates->present($request->user()),
            'moves' => $moves->forUser($request->user()),
            'can' => [
                'manageInstance' => $this->managesInstance($request),
            ],
            'key_reach' => $this->keyReach($request->user()),
        ]);
    }

    private function keyReach(User $user): string
    {
        $roles = $user->teams->map(fn(Team $team): string => (string) $team->pivot->role);

        return match (true) {
            $roles->contains(TeamRole::Owner->value), $roles->contains(TeamRole::Admin->value) => 'managed',
            $roles->contains(TeamRole::Developer->value) => 'added',
            default => 'none',
        };
    }

    /**
     * @return array{name: string, servers: int, provider_servers: int, providers: list<string>, unconfirmed: list<array{name: string, provider: string}>, refusal: string|null}|null
     */
    private function teamDeletion(Request $request, ?Team $team, TeamDeletion $deletion): ?array
    {
        if (!Edition::current()->isCloud() || $team === null || !$request->user()->can('delete', $team)) {
            return null;
        }

        $servers = $team->servers()->with('cloudProvider')->oldest()->orderBy('id')->get();
        $created = $servers->filter(fn(Server $server): bool => $server->cloud_provider_id !== null && $server->provider_server_id !== null);

        return [
            'name' => $team->name,
            'servers' => $servers->count(),
            'provider_servers' => $created->count(),
            'providers' => $created->map(fn(Server $server): ?string => $server->cloudProvider?->type->label())->filter()->unique()->values()->all(),
            'unconfirmed' => $servers
                ->filter(fn(Server $server): bool => $server->cloudProvider !== null && CreateServerTask::uncertain($server))
                ->map(fn(Server $server): array => ['name' => $server->name, 'provider' => $server->cloudProvider->type->label()])
                ->values()
                ->all(),
            'refusal' => $deletion->refusal($team),
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->managesInstance($request), 403);

        $validated = $request->validate([
            'instance_name' => ['sometimes', 'required', 'string', 'max:60'],
            'timezone' => ['sometimes', 'required', Rule::in(DateTimeZone::listIdentifiers())],
            'notification_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            InstanceSetting::put($key, $value);
        }

        return back()->with('success', 'Saved.');
    }

    private function managesInstance(Request $request): bool
    {
        return $request->user()->managesInstance();
    }

    private function type(string $publicKey): string
    {
        return match (true) {
            str_starts_with($publicKey, 'ssh-ed25519') => 'ed25519',
            str_starts_with($publicKey, 'ssh-rsa') => 'rsa',
            str_starts_with($publicKey, 'ecdsa') => 'ecdsa',
            default => 'key',
        };
    }
}
