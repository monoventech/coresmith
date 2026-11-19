<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\User;
use App\Services\Network\ServerAddresses;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeDetector;
use App\Services\Runtime\RuntimeLifecycle;
use App\Stacks\GoFrameworks;
use App\Stacks\JavaFrameworks;
use App\Stacks\JsPresets\JsPresets;
use App\Stacks\PythonPresets\PythonPresets;

final class AddAppForm
{
    public function __construct(private readonly Catalog $catalog, private readonly RuntimeLifecycle $lifecycle) {}

    /**
     * @return array{choices: list<array{value: string, name: string, type: string}>, can_create: bool}
     */
    public function databases(User $user, Server $server): array
    {
        return [
            'choices' => ManagedDatabase::query()->where('server_id', $server->getKey())->where('status', '!=', ManagedDatabase::FAILED)->orderBy('name')->get()
                ->filter(fn(ManagedDatabase $database): bool => $user->can('viewCredentials', $database))
                ->map(fn(ManagedDatabase $database): array => ['value' => 'existing:' . $database->getKey(), 'name' => (string) $database->name, 'type' => (string) $database->type?->value])
                ->values()
                ->all(),
            'can_create' => $server->status === ServerStatus::Active && $user->hasTeamAbility($server->team, Ability::CreateDatabase),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function props(User $user, Server $server): array
    {
        return [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.show', $server), 'dns_targets' => ServerAddresses::targetsFor($server)],
            'js_frameworks' => app(JsPresets::class)->choices(),
            'python_frameworks' => app(PythonPresets::class)->choices(),
            'go_frameworks' => GoFrameworks::options(),
            'java_frameworks' => JavaFrameworks::options(),
            'runtimes' => collect(Catalog::RUNTIMES)->mapWithKeys(fn(string $runtime): array => [$runtime => [
                'label' => $this->catalog->label($runtime),
                'recommended' => $this->catalog->recommendedLine($runtime),
                'sources' => RuntimeDetector::SOURCES[$runtime],
                'options' => $this->lifecycle->options($runtime),
            ]])->all(),
            'github' => [
                'connections' => SourceProvider::query()
                    ->where('team_id', $server->team_id)
                    ->where('type', SourceProviderType::GitHubApp->value)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get()
                    ->filter(fn(SourceProvider $provider): bool => $provider->connected() && $provider->usableBy($user, $server->team))
                    ->map(fn(SourceProvider $provider): array => [
                        'id' => $provider->id,
                        'account' => $provider->name,
                        'repositories_href' => route('source-providers.repositories', $provider),
                        'manage_url' => $provider->manageUrl(),
                    ])
                    ->values()
                    ->all(),
                'settings_href' => route('source-providers.index'),
            ],
        ];
    }
}
