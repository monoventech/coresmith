<?php

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Enums\Edition;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingPresenter;
use App\Services\Panel\CommandPalette;
use App\Services\Panel\FleetSnapshot;
use App\Services\Panel\Realtime;
use App\Services\Panel\SetupProgress;
use App\Services\Teams\TeamContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $this->forwardFlash($request);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'appName' => Edition::current()->isCloud() ? config('app.name') : InstanceSetting::get('instance_name', config('app.name')),
            'edition' => Edition::current()->value,
            'vaultKeyConfigured' => !empty(config('coresmith.vault_key')),
            'panel' => fn(): ?array => $this->panel($request),
            'billing' => fn(): ?array => $this->billing($request),
            'palette' => Inertia::optional(fn(): array => $request->user() === null
                ? []
                : (new CommandPalette($request->user(), app(TeamContext::class)->team()))->commands()),
        ];
    }

    private function forwardFlash(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $flash = array_filter([
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
            'plainTextToken' => $request->session()->get('plainTextToken'),
        ], fn(mixed $value): bool => is_string($value) && $value !== '');

        if ($flash !== []) {
            Inertia::flash($flash);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function billing(Request $request): ?array
    {
        $user = $request->user();
        $team = $user instanceof User ? app(TeamContext::class)->team() : null;

        if (Edition::current()->isSelfHosted() || !$user instanceof User || $team === null) {
            return null;
        }

        return app(BillingPresenter::class)->summary($team, $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function panel(Request $request): ?array
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return null;
        }

        $context = app(TeamContext::class);
        $teams = $user->teams()->withCount(['servers', 'members'])->orderBy('teams.name')->get();
        $team = $context->team();
        $fleet = $team === null ? null : new FleetSnapshot($team);
        [$current, $others] = $teams->partition(fn(Team $each): bool => $each->id === $team?->id);

        return [
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug, 'role' => $user->roleIn($team)?->label()],
            'teams' => $current->concat($others)->map(fn(Team $each): array => [
                'id' => $each->id,
                'name' => $each->name,
                'slug' => $each->slug,
                'role' => $user->roleIn($each)?->label(),
                'servers' => (int) $each->servers_count,
                'members' => (int) $each->members_count,
                'current' => $each->id === $team?->id,
            ])->values()->all(),
            'base' => $context->base(),
            'viaHost' => $context->viaHost(),
            'setup' => $team === null ? null : (new SetupProgress($team))->summary(),
            'attention' => $fleet === null ? 0 : count($fleet->attention()),
            'can' => [
                'createServer' => $team !== null && $user->hasTeamAbility($team, Ability::CreateServer),
            ],
            'live' => [
                'streaming' => Realtime::available(),
                'refresh_seconds' => (int) config('coresmith.live.refresh_seconds'),
            ],
        ];
    }
}
