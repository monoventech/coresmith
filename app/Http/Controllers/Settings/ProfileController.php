<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Edition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Deployment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('account/Profile', [
            'mustVerifyEmail' => Edition::current()->isCloud(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit')->with('success', 'Saved.');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();
        $owned = $user->ownedTeams()->first();

        if ($owned !== null) {
            return back()->withErrors(['password' => "Hand {$owned->name} to another owner before deleting your account."]);
        }

        $servers = SyncAuthorizedKeys::activeServersOf($user->teams()->pluck('teams.id'));

        Auth::logout();

        DB::transaction(function () use ($user): void {
            Deployment::query()->where('triggered_by', $user->getKey())->update(['triggered_by' => null]);
            $user->tokens()->delete();
            $user->sshKeys()->delete();
            $user->delete();
        });

        SyncAuthorizedKeys::dispatchForServers($servers);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
