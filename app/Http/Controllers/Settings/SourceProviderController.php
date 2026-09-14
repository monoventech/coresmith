<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\SourceProvider;
use App\Models\WebhookEndpoint;
use App\Services\Git\GitProviderFactory;
use App\Services\Git\Repository;
use App\Services\SSH\HostKeyFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SourceProviderController extends Controller
{
    public function __construct(private readonly GitProviderFactory $clients) {}

    public function index(Request $request): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);
        $this->authorize('view', $team);

        $applications = $team->applications()->with(['server', 'webhookEndpoints'])->orderBy('name')->get();
        $lastDelivery = $applications->flatMap(fn(Application $application) => $application->webhookEndpoints)
            ->filter(fn(WebhookEndpoint $endpoint): bool => $endpoint->last_delivered_at !== null)
            ->sortByDesc('last_delivered_at')
            ->first();

        return Inertia::render('settings/Git', [
            'deployKeys' => $applications
                ->filter(fn(Application $application): bool => $application->deploy_key_public !== null)
                ->map(fn(Application $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'fingerprint' => HostKeyFingerprint::fromPublicKey((string) $application->deploy_key_public),
                    'repository' => Repository::slug((string) $application->repository_url),
                    'href' => route('servers.applications.show', [$application->server_id, $application]),
                ])->values(),
            'webhooks' => [
                'count' => $applications->sum(fn(Application $application): int => $application->webhookEndpoints->where('active', true)->count()),
                'last' => $lastDelivery === null ? null : [
                    'id' => $lastDelivery->last_delivery_id === null ? null : substr($lastDelivery->last_delivery_id, 0, 8),
                    'event' => $lastDelivery->last_event,
                    'status' => $lastDelivery->last_status,
                    'verified' => $lastDelivery->last_status !== 401,
                    'when' => $lastDelivery->last_delivered_at?->diffForHumans(),
                ],
            ],
            'providers' => $team->sourceProviders()->latest()->get()->map(fn(SourceProvider $provider): array => [
                'id' => $provider->id,
                'type' => $provider->type->value,
                'label' => $provider->type->label(),
                'auth_description' => $provider->type->authDescription(),
                'name' => $provider->name,
                'scopes' => $provider->type->scopes(),
                'custom_host' => $provider->custom_host,
                'management_url' => $provider->type->managementUrl(),
                'connected' => $provider->connected(),
                'expires_at' => $provider->token_expires_at?->toIso8601String(),
                'connected_at' => $provider->created_at?->toIso8601String(),
            ]),
            'connectable' => collect(SourceProviderType::cases())
                ->filter(fn(SourceProviderType $type): bool => $type->usesTokens())
                ->map(fn(SourceProviderType $type): array => [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'scopes' => $type->scopes(),
                    'configured' => $this->isConfigured($type),
                ])->values(),
            'can' => ['manage' => $request->user()->can('update', $team)],
        ]);
    }

    public function destroy(Request $request, SourceProvider $sourceProvider): RedirectResponse
    {
        $this->authorize('update', $sourceProvider->team);

        if ($sourceProvider->type->usesTokens() && $sourceProvider->connected()) {
            try {
                $this->clients->for($sourceProvider)->revoke();
            } catch (GitProviderException | Throwable) {
                report_if(app()->hasDebugModeEnabled(), new GitProviderException('Revoking the remote token failed; the local credentials were still deleted.'));
            }
        }

        $sourceProvider->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'app_private_key' => null,
            'token_expires_at' => null,
        ])->save();

        $sourceProvider->delete();

        return back()->with('success', 'Disconnected. Apps keep deploying with their own deploy keys.');
    }

    private function isConfigured(SourceProviderType $type): bool
    {
        return match ($type) {
            SourceProviderType::GitHubApp => (string) config('services.github.app_id') !== '',
            SourceProviderType::GitLab => (string) config('services.gitlab.client_id') !== '',
            SourceProviderType::Bitbucket => (string) config('services.bitbucket.client_id') !== '',
            SourceProviderType::Custom => true,
        };
    }
}
