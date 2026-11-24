<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Jobs\RunTerminal;
use App\Models\Application;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Panel\Realtime;
use App\Services\Panel\ServerPresenter;
use App\Services\Teams\TeamAccess;
use App\Services\Terminal\TerminalScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TerminalController extends Controller
{
    public const CONFIRMED_SECONDS = 900;

    public function create(Request $request, Server $server, ServerPresenter $presenter, TeamAccess $access): Response|RedirectResponse
    {
        $user = $request->user();
        $root = $user->can('openRootTerminal', $server);
        $app = $user->can('openTerminal', $server);

        abort_unless($root || $app, 403);

        $live = $this->live($user, $server);

        if ($live !== null) {
            return to_route('servers.terminal.show', [$server, $live]);
        }

        return Inertia::render('servers/TerminalStart', [
            'server' => $presenter->header($server, $user),
            'modes' => [
                'app' => ['allowed' => $app, 'reason' => $app ? null : "You can open a terminal only on servers you added."],
                'root' => ['allowed' => $root, 'reason' => $root ? null : $this->rootRefusal($user, $server)],
            ],
            'applications' => $this->applications($access, $user, $server),
            'chosen' => $request->query('app'),
            'password' => !$this->confirmed($request),
            'unavailable' => $this->unavailable($server),
            'open_href' => route('servers.terminal.store', $server),
        ]);
    }

    public function store(Request $request, Server $server, TeamAccess $access): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'mode' => ['required', Rule::enum(TerminalMode::class)],
            'application' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'cols' => ['nullable', 'integer', 'between:20,500'],
            'rows' => ['nullable', 'integer', 'between:5,200'],
        ], [
            'mode.required' => 'Choose who the shell runs as.',
            'mode.enum' => 'Choose who the shell runs as.',
        ]);

        $mode = TerminalMode::from($validated['mode']);
        $this->authorize($mode === TerminalMode::Root ? 'openRootTerminal' : 'openTerminal', $server);

        $live = $this->live($user, $server);

        if ($live !== null) {
            return to_route('servers.terminal.show', [$server, $live]);
        }

        if (($reason = $this->unavailable($server)) !== null) {
            throw ValidationException::withMessages(['mode' => $reason]);
        }

        $application = null;

        if ($mode === TerminalMode::App) {
            $application = $access->applicationsOn($user, $server)
                ->whereNotNull('current_release')
                ->get()
                ->first(fn(Application $candidate): bool => in_array((string) ($validated['application'] ?? ''), [(string) $candidate->getRouteKey(), (string) $candidate->getKey()], true));

            if ($application === null) {
                throw ValidationException::withMessages(['application' => 'Choose an app that has a live release on this server.']);
            }
        }

        if (!$this->confirmed($request)) {
            if (!is_string($validated['password'] ?? null) || $validated['password'] === '') {
                throw ValidationException::withMessages(['password' => 'Enter your password to open a terminal.']);
            }

            $request->validate(['password' => ['current_password']], ['password.current_password' => "That password isn't right."]);
            $request->session()->put('auth.password_confirmed_at', time());
        }

        if (TerminalSession::query()->live()->count() >= (int) config('coresmith.terminals.max')) {
            throw ValidationException::withMessages(['mode' => 'Coresmith is running as many terminals as it can right now. Try again when one ends.']);
        }

        $session = TerminalSession::query()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'user_id' => $user->id,
            'application_id' => $application?->id,
            'mode' => $mode,
            'status' => TerminalStatus::Starting,
            'cols' => (int) ($validated['cols'] ?? 120),
            'rows' => (int) ($validated['rows'] ?? 32),
        ]);

        RunTerminal::dispatch($session);

        return to_route('servers.terminal.show', [$server, $session]);
    }

    public function show(Request $request, Server $server, TerminalSession $terminal, ServerPresenter $presenter, TerminalScreen $screens): Response
    {
        abort_unless($terminal->user_id === $request->user()->id, 404);

        $terminal->loadMissing('application');

        return Inertia::render('servers/TerminalSession', [
            'server' => $presenter->header($server, $request->user()),
            'terminal' => TerminalController::present($terminal),
            'links' => [
                'input' => route('servers.terminal.input', [$server, $terminal]),
                'resize' => route('servers.terminal.resize', [$server, $terminal]),
                'check_in' => route('servers.terminal.check-in', [$server, $terminal]),
                'screen' => route('servers.terminal.screen', [$server, $terminal]),
                'end' => route('servers.terminal.destroy', [$server, $terminal]),
                'again' => route('servers.terminal', $server),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(TerminalSession $terminal): array
    {
        return [
            'id' => $terminal->getKey(),
            'mode' => $terminal->mode->value,
            'application' => $terminal->application?->name,
            'as' => $terminal->mode === TerminalMode::Root ? 'root' : (string) $terminal->application?->system_user,
            'status' => $terminal->status->value,
            'started_at' => $terminal->started_at?->getTimestamp(),
            'ends_at' => $terminal->started_at?->addSeconds(RunTerminal::LIMIT_SECONDS)->getTimestamp(),
            'idle_seconds' => RunTerminal::IDLE_SECONDS,
            'ended' => $terminal->live() ? null : [
                'reason' => $terminal->end_reason?->value,
                'sentence' => $terminal->end_reason?->sentence(),
                'minutes' => $terminal->started_at === null || $terminal->ended_at === null ? 0 : (int) round($terminal->started_at->diffInMinutes($terminal->ended_at)),
                'changes' => array_values((array) $terminal->changes),
            ],
        ];
    }

    private function live(User $user, Server $server): ?TerminalSession
    {
        return TerminalSession::query()->live()->where('user_id', $user->id)->where('server_id', $server->id)->latest()->first();
    }

    private function confirmed(Request $request): bool
    {
        return time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < self::CONFIRMED_SECONDS;
    }

    private function unavailable(Server $server): ?string
    {
        return match (true) {
            !Realtime::available() || Realtime::socket() === null => "Terminals need Coresmith's live connection, and it isn't set up on this install.",
            $server->team?->moving_at !== null, $server->status === ServerStatus::Moving => 'This server is moving, so terminals are closed until the move finishes.',
            $server->status !== ServerStatus::Active => "This server isn't set up yet. Open a terminal once it's ready.",
            $server->pending_host_key_fingerprint !== null => "This server's host key changed. Review it on the server's page first.",
            $server->unreachable_since !== null => "Coresmith can't reach this server right now, so it can't open a terminal there.",
            default => null,
        };
    }

    private function rootRefusal(User $user, Server $server): string
    {
        return $user->roleIn($server->team) === TeamRole::Owner && !$server->team?->root_terminals
            ? 'Root terminals are off for this team. An owner can turn them on under Settings, Team.'
            : 'Only owners can open a root shell.';
    }

    /**
     * @return list<array{key: string, name: string, user: string}>
     */
    private function applications(TeamAccess $access, User $user, Server $server): array
    {
        return $access->applicationsOn($user, $server)
            ->whereNotNull('current_release')
            ->orderBy('name')
            ->get()
            ->map(fn(Application $application): array => ['key' => (string) $application->getRouteKey(), 'name' => (string) $application->name, 'user' => (string) $application->system_user])
            ->values()
            ->all();
    }
}
