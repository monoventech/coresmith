<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Ability;
use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CreateServerTask;

final class CloudPresenter
{
    private const RESIZE_STEPS = [
        'power_off' => 'shutting it down',
        'wait_off' => 'shutting it down',
        'change' => 'changing the size',
        'wait_change' => 'changing the size',
        'power_on' => 'starting it again',
        'wait_on' => 'starting it again',
        'recover' => 'starting it again',
        'reconnect' => 'signing back in',
    ];

    private const CREATE_STEPS = [
        'create' => ['Create the server', "with Coresmith's SSH key on it", 'creating it'],
        'boot' => ['Boot', 'and get a public IP address', 'waiting for it to boot'],
        'ssh' => ['Sign in over SSH', 'with the key made for this server', 'signing in over SSH'],
        'settle' => ['Let first-boot setup finish', 'cloud-init and package updates', 'waiting for first-boot setup'],
    ];

    public function __construct(private readonly CloudCatalog $catalogs) {}

    /**
     * @return array{accounts: list<array{id: string, type: string, provider: string, name: string, label: ?string, refused: bool, refused_on: ?string, servers: int}>, types: list<array{value: string, label: string, help: string, token_url: string, permission: string}>, can_manage: bool}
     */
    public function accounts(Team $team, User $user): array
    {
        return [
            'accounts' => CloudProvider::query()
                ->where('team_id', $team->getKey())
                ->withCount('servers')
                ->oldest()
                ->orderBy('id')
                ->get()
                ->map(fn(CloudProvider $account): array => [
                    'id' => (string) $account->getKey(),
                    'type' => $account->type->value,
                    'provider' => $account->type->label(),
                    'name' => (string) $account->name,
                    'label' => $account->account_label,
                    'refused' => $account->isRefused(),
                    'refused_on' => $account->token_failed_at?->format('M j'),
                    'servers' => (int) $account->servers_count,
                ])
                ->values()
                ->all(),
            'types' => self::types(),
            'can_manage' => $user->hasTeamAbility($team, Ability::ManageProviders),
        ];
    }

    /**
     * @return list<array{value: string, label: string, help: string, token_url: string, permission: string}>
     */
    public static function types(): array
    {
        return array_map(fn(CloudProviderType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'help' => $type->tokenHelp(),
            'token_url' => $type->tokenUrl(),
            'permission' => $type->permissionHint(),
        ], CloudProviderType::cases());
    }

    /**
     * @return array{accounts: list<array<string, mixed>>, types: list<array<string, string>>, selected: string, can_connect: bool}
     */
    public function choices(Team $team, User $user, ?string $requested): array
    {
        $accounts = $this->accounts($team, $user);
        $connected = array_column($accounts['accounts'], 'type');
        $selected = $this->selectedAccount($team, $requested);

        return [
            'accounts' => $accounts['accounts'],
            'types' => array_values(array_filter(self::types(), fn(array $type): bool => !in_array($type['value'], $connected, true))),
            'selected' => $selected === null ? 'manual' : 'account:' . $selected->getKey(),
            'can_connect' => $accounts['can_manage'],
        ];
    }

    public function selectedAccount(Team $team, ?string $requested): ?CloudProvider
    {
        $accounts = CloudProvider::query()->where('team_id', $team->getKey())->oldest()->orderBy('id')->get();

        return ($requested === null ? null : $accounts->firstWhere('id', $requested))
            ?? $accounts->first(fn(CloudProvider $account): bool => !$account->isRefused())
            ?? $accounts->first();
    }

    /**
     * @return array{error: ?string, provider: string, regions: list<array<string, string>>, sizes: list<array<string, mixed>>, images: list<array<string, mixed>>, defaults: array<string, ?string>}
     */
    public function catalog(CloudProvider $account): array
    {
        $label = $account->type->label();

        if ($account->isRefused()) {
            return $this->unavailable($label, self::refusal($account));
        }

        try {
            $data = $this->catalogs->for($account);
        } catch (CloudProviderException $e) {
            if ($e->failure === CloudFailure::TokenRejected) {
                $account->forceFill(['token_failed_at' => now()])->save();
            }

            return $this->unavailable($label, "Coresmith couldn't load {$label}'s regions and sizes. {$e->getMessage()}");
        }

        $everywhere = $data->regions()->map(fn(CloudRegion $region): string => $region->id)->values()->all();

        return [
            'error' => null,
            'provider' => $label,
            'regions' => $data->regions()->map(fn(CloudRegion $region): array => ['id' => $region->id, 'label' => $region->label])->values()->all(),
            'sizes' => $data->sizes()->map(function (CloudSize $size) use ($everywhere): array {
                $regions = array_values($size->regions ?? $everywhere);
                $prices = [];

                foreach ($regions as $region) {
                    $price = $size->priceIn($region);
                    $prices[$region] = $price === null ? null : Formats::money($price->monthly, $price->currency);
                }

                return [
                    'id' => $size->id,
                    'label' => $size->label(),
                    'cpus' => $size->cpus,
                    'memory_mb' => $size->memoryMb,
                    'disk_gb' => $size->diskGb,
                    'architecture' => $size->architecture,
                    'regions' => $regions,
                    'prices' => $prices,
                    'price' => $size->price === null ? null : Formats::money($size->price->monthly, $size->price->currency),
                ];
            })->values()->all(),
            'images' => $data->images()->map(fn(CloudImage $image): array => ['id' => $image->id, 'label' => $image->label, 'architectures' => $image->architectures])->values()->all(),
            'defaults' => $data->defaults(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function server(Server $server, User $user): ?array
    {
        $account = $server->cloud_provider_id === null ? null : $server->cloudProvider;

        if ($account === null) {
            return null;
        }

        $task = CloudTask::of($server);
        $failedCreate = $task !== null && $task->type === CloudTask::CREATE && $task->state === CloudTask::FAILED;
        $failedDestroy = $task !== null && $task->type === CloudTask::DESTROY && $task->state === CloudTask::FAILED;

        return [
            'provider' => $account->type->label(),
            'type' => $account->type->value,
            'account' => $account->name,
            'instance' => $server->provider_server_id,
            'ip' => $server->ip_address,
            'region' => $server->region,
            'size' => $server->provider_size,
            'image' => is_string($task?->data['image_label'] ?? null) ? $task->data['image_label'] : $server->provider_image,
            'monthly' => Formats::money($server->provider_monthly_cost === null ? null : (string) $server->provider_monthly_cost, $server->provider_currency),
            'console_url' => $account->type->consoleUrl($server->provider_server_id),
            'task' => $task === null ? null : $this->task($task, $account->type->label()),
            'can' => [
                'retry' => ($failedCreate && $user->can('update', $server)) || ($failedDestroy && $user->can('delete', $server)),
                'remove' => $failedCreate && $server->provider_server_id === null && $user->can('delete', $server),
                'detach' => $task?->state !== CloudTask::RUNNING && $user->can('delete', $server),
                'destroy' => filled($server->provider_server_id) && $task?->state !== CloudTask::RUNNING && $user->can('delete', $server),
                'resize' => $server->status === ServerStatus::Active && filled($server->provider_server_id) && $task?->state !== CloudTask::RUNNING && $user->can('update', $server),
                'dismiss' => $task !== null && $task->type === CloudTask::RESIZE && $task->state === CloudTask::FAILED && $user->can('update', $server),
            ],
        ];
    }

    public static function refusal(CloudProvider $account): string
    {
        $label = $account->type->label();

        return "{$label} refused this account's token on {$account->token_failed_at?->format('M j')}. Replace it in Settings → Team, then try again.";
    }

    /**
     * @return array{error: string, provider: string, regions: array{}, sizes: array{}, images: array{}, defaults: array{region: null, size: null, image: null}}
     */
    private function unavailable(string $label, string $error): array
    {
        return ['error' => $error, 'provider' => $label, 'regions' => [], 'sizes' => [], 'images' => [], 'defaults' => ['region' => null, 'size' => null, 'image' => null]];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(CloudTask $task, string $label): array
    {
        $steps = $task->type === CloudTask::CREATE ? CreateServerTask::STEPS : [$task->step];
        $position = array_search($task->step, $steps, true);
        $position = $position === false ? 0 : (int) $position;

        return [
            'type' => $task->type,
            'state' => $task->state,
            'step' => $task->step,
            'step_label' => match ($task->type) {
                CloudTask::DESTROY => "deleting it at {$label}",
                CloudTask::RESIZE => self::RESIZE_STEPS[$task->step] ?? $task->step,
                default => self::CREATE_STEPS[$task->step][2] ?? $task->step,
            },
            'position' => $position + 1,
            'total' => count($steps),
            'steps' => array_map(fn(string $step, int $index): array => [
                'key' => $step,
                'name' => self::CREATE_STEPS[$step][0] ?? $step,
                'desc' => self::CREATE_STEPS[$step][1] ?? '',
                'state' => match (true) {
                    $task->state === CloudTask::DONE || $index < $position => 'done',
                    $index === $position => $task->state === CloudTask::FAILED ? 'failed' : 'running',
                    default => 'pending',
                },
            ], $steps, array_keys($steps)),
            'error' => $task->error,
            'to' => is_string($task->data['to'] ?? null) ? $task->data['to'] : null,
        ];
    }
}
