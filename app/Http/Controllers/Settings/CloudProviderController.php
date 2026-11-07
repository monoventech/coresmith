<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCloudProviderRequest;
use App\Http\Requests\Settings\UpdateCloudProviderRequest;
use App\Models\CloudProvider;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\Contracts\CloudApiFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CloudProviderController extends Controller
{
    public function store(StoreCloudProviderRequest $request, CloudApiFactory $apis): RedirectResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $type = CloudProviderType::from((string) $request->validated('type'));
        $token = (string) $request->validated('api_token');

        try {
            $verified = $apis->make($type, $token)->verify();
        } catch (CloudProviderException $e) {
            return back()->withErrors(['api_token' => $e->connectMessage()])->withInput($request->except('api_token'));
        }

        $account = CloudProvider::query()->create([
            'team_id' => $team->getKey(),
            'type' => $type,
            'name' => (string) $request->validated('name'),
            'api_token' => $token,
            'account_label' => $verified->label,
            'verified_at' => now(),
        ]);

        activity()
            ->performedOn($account)
            ->withProperties(['provider' => $type->label(), 'name' => $account->name])
            ->log('cloud_provider_connected');

        return match ($request->validated('return_to')) {
            'provision' => redirect()->route('servers.create', ['account' => $account->getKey()])->with('success', 'Connected. Pick a region and a size.'),
            'setup' => redirect()->route('setup.show', ['step' => 1, 'account' => $account->getKey()])->with('success', 'Connected. Pick a region and a size.'),
            default => back()->with('success', 'Connected.'),
        };
    }

    public function update(UpdateCloudProviderRequest $request, CloudProvider $cloudProvider, CloudApiFactory $apis, CloudCatalog $catalog): RedirectResponse
    {
        $label = $cloudProvider->type->label();
        $token = $request->validated('api_token');

        if ($token !== null) {
            try {
                $api = $apis->make($cloudProvider->type, (string) $token);
                $api->verify();
                $server = $cloudProvider->servers()->whereNotNull('provider_server_id')->oldest()->orderBy('id')->first();

                if ($server !== null && $api->find((string) $server->provider_server_id) === null) {
                    return back()->withErrors(['api_token' => "That token can't see {$server->name}, which was created with this account. Paste a token from the same {$label} account."]);
                }
            } catch (CloudProviderException $e) {
                return back()->withErrors(['api_token' => $e->connectMessage()]);
            }
        }

        $name = $request->validated('name');

        if ($name !== null) {
            $cloudProvider->forceFill(['name' => (string) $name]);
        }

        if ($token !== null) {
            $cloudProvider->forceFill(['api_token' => (string) $token, 'token_failed_at' => null, 'verified_at' => now()]);
        }

        $cloudProvider->save();

        if ($token !== null) {
            $catalog->forget($cloudProvider);

            activity()
                ->performedOn($cloudProvider)
                ->withProperties(['provider' => $label, 'name' => $cloudProvider->name])
                ->log('cloud_provider_token_replaced');
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, CloudProvider $cloudProvider, CloudCatalog $catalog): RedirectResponse
    {
        $team = $this->currentTeam();

        abort_unless($team !== null && $cloudProvider->team_id === $team->getKey(), 404);
        abort_unless($request->user()->hasTeamAbility($team, Ability::ManageProviders), 403);

        $label = $cloudProvider->type->label();
        $servers = $cloudProvider->servers()->oldest()->orderBy('id')->pluck('name');

        if ($servers->isNotEmpty()) {
            $first = (string) $servers->first();

            return back()->with('error', $servers->count() === 1
                ? "{$first} was created with this account. Destroy or detach it first, or replace the token instead."
                : "{$first} and " . ($servers->count() - 1) . ' more were created with this account. Destroy or detach them first, or replace the token instead.');
        }

        $cloudProvider->forceFill(['api_token' => null])->save();
        $cloudProvider->delete();
        $catalog->forget($cloudProvider);

        activity()
            ->performedOn($cloudProvider)
            ->withProperties(['provider' => $label, 'name' => $cloudProvider->name])
            ->log('cloud_provider_removed');

        return back()->with('success', "Removed. Revoke the token at {$label} too.");
    }
}
