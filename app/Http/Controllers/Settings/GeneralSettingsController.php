<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Jobs\BackUpPanel;
use App\Models\InstanceSetting;
use App\Models\SshKey;
use App\Services\Panel\PanelBackup;
use App\Services\Panel\PanelUpdatesPresenter;
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

    public function show(Request $request, PanelBackup $backups, PanelUpdatesPresenter $updates): Response
    {
        $installed = InstanceSetting::get('installed_at');
        $latest = $backups->latest();

        return Inertia::render('settings/General', [
            'instance' => [
                'name' => InstanceSetting::get('instance_name', config('app.name')),
                'version' => (string) config('coresmith.version', '0.1.0'),
                'installed' => is_string($installed) ? CarbonImmutable::parse($installed)->format('M j, Y') : null,
                'timezone' => InstanceSetting::get('timezone', config('app.timezone')),
                'notification_email' => InstanceSetting::get('notification_email'),
            ],
            'backups' => [
                'last' => $latest === null ? null : [
                    'when' => $latest['at']->isToday() ? 'today ' . $latest['at']->format('H:i') : $latest['at']->format('M j, H:i'),
                    'size' => Number::fileSize($latest['bytes'], precision: 1),
                    'verified' => $latest['verified'],
                ],
                'keep' => PanelBackup::KEEP,
            ],
            'owns_team' => $request->user()->ownedTeams()->exists(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'keys' => $request->user()->sshKeys()->latest()->get()->map(fn(SshKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'type' => $this->type($key->public_key),
                'fingerprint' => $key->fingerprint,
                'added' => $key->created_at?->format('M Y'),
            ])->values(),
            'updates' => $updates->present($request->user()),
            'can' => [
                'manageInstance' => $this->managesInstance($request),
                'reachServers' => $request->user()->teams->contains(fn($team): bool => in_array($team->pivot->role, [TeamRole::Owner->value, TeamRole::Admin->value], true)),
            ],
        ]);
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
