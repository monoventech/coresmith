<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Enums\SourceProviderType;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamUrls;
use Illuminate\Support\Str;

final class ManifestBuilder
{
    /** @var array<string, string> */
    public const PERMISSIONS = ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'];

    /** @var list<string> */
    public const EVENTS = ['push'];

    public const ORGANIZATION_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}\z/';

    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 -]{0,33}\z/';

    private const NAME_PREFIX = 'Coresmith';

    private const NAME_LIMIT = 34;

    public function __construct(private readonly TeamUrls $urls) {}

    /**
     * @return array<string, mixed>
     */
    public function base(): array
    {
        return [
            'url' => $this->urls->canonical('/'),
            'redirect_url' => $this->redirectUrl(),
            'callback_urls' => [$this->callbackUrl()],
            'description' => "Deploys from {$this->urls->mainHost()} with Coresmith. Reads repository contents and posts commit statuses.",
            'public' => false,
            'default_permissions' => self::PERMISSIONS,
            'request_oauth_on_install' => true,
            'setup_on_update' => false,
        ];
    }

    /**
     * @return array{hook_attributes: array{url: string, active: bool}, default_events: list<string>}
     */
    public function webhook(string $appKey): array
    {
        return [
            'hook_attributes' => ['url' => $this->webhookUrl($appKey), 'active' => true],
            'default_events' => self::EVENTS,
        ];
    }

    public function personalAction(string $state): string
    {
        return GitHubAppApi::WEB . '/settings/apps/new?' . http_build_query(['state' => $state]);
    }

    public function organizationAction(string $state): string
    {
        return GitHubAppApi::WEB . '/organizations/{organization}/settings/apps/new?' . http_build_query(['state' => $state]);
    }

    public function personalName(User $user): string
    {
        $spaced = (string) preg_replace('/\s+/', ' ', Str::ascii((string) $user->name));
        $words = trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace('/[^A-Za-z0-9 -]/', '', $spaced)));

        return rtrim(substr(self::NAME_PREFIX . ' for ' . $words, 0, self::NAME_LIMIT), ' -');
    }

    public function defaultName(Team $team): string
    {
        $spaced = (string) preg_replace('/\s+/', ' ', Str::ascii((string) $team->name));
        $allowed = (string) preg_replace('/[^A-Za-z0-9 -]/', '', $spaced);
        $words = trim((string) preg_replace('/ {2,}/', ' ', $allowed));

        return rtrim(substr(self::NAME_PREFIX . ' ' . $words, 0, self::NAME_LIMIT), ' -');
    }

    public function redirectUrl(): string
    {
        return $this->urls->canonical(route('github-apps.registered', [], false));
    }

    public function callbackUrl(): string
    {
        return $this->urls->canonical(route('source-providers.callback', SourceProviderType::GitHubApp->value, false));
    }

    public function webhookUrl(string $appKey): string
    {
        return $this->urls->canonical(route('webhooks.github.app', $appKey, false));
    }
}
