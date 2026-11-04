<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PlanFeature;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Services\Billing\PlanGate;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SsoConnections;
use App\Services\Sso\SsoException;
use App\Services\Teams\TeamContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SingleSignOnController extends Controller
{
    public function update(Request $request, TeamContext $context, SsoConnections $connections, PlanGate $plans): RedirectResponse
    {
        $team = $this->ownedTeam($request, $context);

        $plans->ensureIncludes($team, PlanFeature::SingleSignOn, $request->user());

        $existing = $connections->forTeam($team);

        $request->merge([
            'issuer' => $connections->normaliseIssuer((string) $request->input('issuer')),
            'client_id' => trim((string) $request->input('client_id')),
            'client_secret' => trim((string) $request->input('client_secret')),
            'email_domain' => $connections->normaliseDomain((string) $request->input('email_domain')),
        ]);

        $issuerChanged = $existing?->issuer !== $request->input('issuer');
        $clientChanged = $existing?->client_id !== $request->input('client_id');

        $validated = $request->validate([
            'issuer' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($connections): void {
                $refusal = $connections->issuerRefusal((string) $value);

                if ($refusal !== null) {
                    $fail($refusal);
                }
            }],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$issuerChanged || $clientChanged ? 'required' : 'nullable', 'string', 'max:4096'],
            'email_domain' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail) use ($connections, $team): void {
                $refusal = $connections->domainRefusal((string) $value, $team);

                if ($refusal !== null) {
                    $fail($refusal);
                }
            }],
        ], [
            'issuer.required' => 'Enter the issuer URL from your identity provider.',
            'client_id.required' => 'Enter the client ID from your identity provider.',
            'client_secret.required' => $existing === null
                ? 'Enter the client secret from your identity provider.'
                : 'Enter the client secret again when you change the issuer or client ID.',
            'email_domain.required' => 'Enter the email domain people sign in with, like example.com.',
        ]);

        $domainChanged = $existing?->email_domain !== $validated['email_domain'];
        $wasEnabled = $existing?->enabled ?? false;
        $secretChanged = filled($validated['client_secret'] ?? null);

        $connection = $existing ?? new TeamSsoConnection(['team_id' => $team->getKey()]);
        $connection->fill([
            'issuer' => $validated['issuer'],
            'client_id' => $validated['client_id'],
            'email_domain' => $validated['email_domain'],
        ]);

        if ($secretChanged) {
            $connection->client_secret = $validated['client_secret'];
        }

        if ($domainChanged) {
            $connection->forceFill(['domain_token' => $connections->newToken(), 'domain_verified_at' => null]);
        }

        if ($domainChanged || $issuerChanged) {
            $connection->enabled = false;
        }

        $connection->save();

        activity()
            ->performedOn($team)
            ->causedBy($request->user())
            ->withProperties([
                'issuer' => $connection->issuer,
                'client_id' => $connection->client_id,
                'email_domain' => $connection->email_domain,
                'secret_changed' => $secretChanged,
            ])
            ->log($existing === null ? 'sso_configured' : 'sso_updated');

        return back()->with('success', match (true) {
            $domainChanged => 'Saved. Add the TXT record below, then check DNS.',
            $wasEnabled && !$connection->enabled => 'Saved. Single sign-on is off until you turn it on again.',
            default => 'Saved.',
        });
    }

    public function verify(Request $request, TeamContext $context, SsoConnections $connections, PlanGate $plans): RedirectResponse
    {
        $team = $this->ownedTeam($request, $context);

        $plans->ensureIncludes($team, PlanFeature::SingleSignOn, $request->user());

        $connection = $this->connection($connections, $team);

        if ($connection->isVerified()) {
            return back()->with('success', 'Verified.');
        }

        if ($connections->claimedElsewhere($connection->email_domain, $team)) {
            return back()->with('error', 'Another team already signs in with that email domain. Use a different domain.');
        }

        $failure = $connections->verify($connection);

        if ($failure !== null) {
            return back()->with('error', $failure === SsoConnections::WRONG_VALUE
                ? 'The TXT record is there but its value does not match. Copy it again from this page.'
                : 'The TXT record is not visible yet. DNS can take a few minutes, check again shortly.');
        }

        activity()
            ->performedOn($team)
            ->causedBy($request->user())
            ->withProperties(['email_domain' => $connection->email_domain])
            ->log('sso_domain_verified');

        return back()->with('success', 'Verified. You can turn on single sign-on now.');
    }

    public function enabled(Request $request, TeamContext $context, SsoConnections $connections, OidcClient $client, PlanGate $plans): RedirectResponse
    {
        $team = $this->ownedTeam($request, $context);
        $connection = $this->connection($connections, $team);

        $request->validate(['enabled' => ['required', 'boolean']]);

        if (!$request->boolean('enabled')) {
            if ($connection->enabled) {
                $connection->forceFill(['enabled' => false])->save();

                activity()->performedOn($team)->causedBy($request->user())->log('sso_disabled');
            }

            return back()->with('success', 'Single sign-on is off.');
        }

        $plans->ensureIncludes($team, PlanFeature::SingleSignOn, $request->user());

        if (!$connection->isVerified()) {
            return back()->with('error', 'Verify the email domain first. Add the TXT record, then check DNS.');
        }

        if ($connections->claimedElsewhere($connection->email_domain, $team, $connection->domain_verified_at)) {
            return back()->with('error', 'Another team already signs in with that email domain. Use a different domain.');
        }

        try {
            $client->discover($connection->issuer, fresh: true);
        } catch (SsoException $e) {
            return back()->with('error', 'Single sign-on is still off. ' . $e->getMessage());
        }

        $connection->forceFill(['enabled' => true])->save();

        activity()
            ->performedOn($team)
            ->causedBy($request->user())
            ->withProperties(['issuer' => $connection->issuer, 'email_domain' => $connection->email_domain])
            ->log('sso_enabled');

        return back()->with('success', 'Single sign-on is on. People at ' . $connection->email_domain . ' can use it to sign in.');
    }

    public function provisioning(Request $request, TeamContext $context, SsoConnections $connections, PlanGate $plans): RedirectResponse
    {
        $team = $this->ownedTeam($request, $context);

        $plans->ensureIncludes($team, PlanFeature::SingleSignOn, $request->user());

        $connection = $this->connection($connections, $team);

        $request->validate(['jit_provisioning' => ['required', 'boolean']]);

        $on = $request->boolean('jit_provisioning');

        if ($connection->jit_provisioning !== $on) {
            $connection->forceFill(['jit_provisioning' => $on])->save();

            activity()
                ->performedOn($team)
                ->causedBy($request->user())
                ->log($on ? 'sso_provisioning_enabled' : 'sso_provisioning_disabled');
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, TeamContext $context, SsoConnections $connections): RedirectResponse
    {
        $team = $this->ownedTeam($request, $context);
        $connection = $this->connection($connections, $team);

        DB::transaction(function () use ($connection): void {
            $connection->identities()->delete();
            $connection->delete();
        });

        activity()
            ->performedOn($team)
            ->causedBy($request->user())
            ->withProperties(['issuer' => $connection->issuer, 'email_domain' => $connection->email_domain])
            ->log('sso_removed');

        return back()->with('success', 'Removed. People sign in with their Coresmith password again.');
    }

    private function ownedTeam(Request $request, TeamContext $context): Team
    {
        $team = $context->teamOrFail();

        abort_unless($request->user()?->roleIn($team) === TeamRole::Owner, 403);

        return $team;
    }

    private function connection(SsoConnections $connections, Team $team): TeamSsoConnection
    {
        $connection = $connections->forTeam($team);

        abort_unless($connection instanceof TeamSsoConnection, 404);

        return $connection;
    }
}
