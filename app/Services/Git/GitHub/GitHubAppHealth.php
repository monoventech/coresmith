<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Services\Panel\Formats;
use App\Services\Teams\TeamUrls;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

final class GitHubAppHealth
{
    private const NO_WEBHOOK = 'The GitHub app has no webhook yet. Point it here to get pushes as they happen.';

    private const MOVED = 'Coresmith\'s address changed after the GitHub app was created. Point its webhook here.';

    private const NO_DELIVERY = 'No delivery from GitHub has reached Coresmith yet.';

    private const NO_PUSH = 'The GitHub app does not send push events, so Coresmith checks for new commits instead. Turn on the Push event in the app\'s settings on GitHub.';

    private const EVIDENCE = ['received_at' => null, 'event' => null, 'delivery_id' => null, 'checked_at' => null, 'latest' => null, 'sends_push' => null, 'repointed_at' => null];

    public function __construct(
        private readonly PanelReachability $reachability,
        private readonly ManifestBuilder $manifest,
        private readonly TeamUrls $urls,
    ) {}

    public function key(#[\SensitiveParameter] GitHubAppCredentials $app): string
    {
        return 'github:app-health:' . config('app.env') . ':' . $app->key;
    }

    public function received(#[\SensitiveParameter] GitHubAppCredentials $app, string $event, ?string $deliveryId): void
    {
        $this->record($app, [
            'received_at' => now()->getTimestamp(),
            'event' => $event,
            'delivery_id' => $deliveryId,
            ...($event === 'push' ? ['sends_push' => true] : []),
        ]);
    }

    /**
     * @param  array{status_code: int, status: string, delivered_at: CarbonInterface, event?: string}|null  $latest
     * @param  list<string>|null  $events
     */
    public function checked(#[\SensitiveParameter] GitHubAppCredentials $app, ?array $latest, ?array $events = null): void
    {
        $delivered = $latest['delivered_at'] ?? null;

        $this->record($app, [
            'checked_at' => now()->getTimestamp(),
            'latest' => $delivered instanceof CarbonInterface ? [
                'status_code' => (int) ($latest['status_code'] ?? 0),
                'status' => (string) ($latest['status'] ?? ''),
                'delivered_at' => $delivered->getTimestamp(),
                'event' => (string) ($latest['event'] ?? ''),
            ] : null,
            ...($events === null ? [] : ['sends_push' => in_array('push', $events, true)]),
        ]);
    }

    public function repointed(#[\SensitiveParameter] GitHubAppCredentials $app): void
    {
        $this->record($app, ['repointed_at' => now()->getTimestamp(), 'received_at' => null, 'event' => null, 'delivery_id' => null, 'latest' => null, 'sends_push' => null]);
    }

    public function expectedHookUrl(#[\SensitiveParameter] GitHubAppCredentials $app): string
    {
        return $app->isEnv() ? $this->urls->canonical(route('webhooks.github', [], false)) : $this->manifest->webhookUrl($app->key);
    }

    public function mode(#[\SensitiveParameter] GitHubAppCredentials $app): WebhookMode
    {
        if (!$app->isEnv() && !$this->hasWebhook($app)) {
            $verdict = $this->reachability->verdict();

            return $verdict->reachable
                ? WebhookMode::polling(WebhookMode::NONE, self::NO_WEBHOOK)
                : WebhookMode::polling(WebhookMode::UNREACHABLE, (string) $verdict->sentence());
        }

        ['received' => $received, 'latest' => $latest, 'sends_push' => $sendsPush] = $this->evidence($app);
        $failed = $latest !== null && !$this->delivered($latest['status_code']);

        return match (true) {
            $sendsPush === false => WebhookMode::polling(WebhookMode::NO_PUSH, self::NO_PUSH),
            $failed && ($received === null || $latest['delivered_at']->greaterThan($received)) => WebhookMode::polling(WebhookMode::FAILING, $this->failure($latest)),
            $received !== null => WebhookMode::instant($received),
            $latest !== null && !$failed => WebhookMode::instant($latest['delivered_at']),
            !$app->isEnv() && $app->hookUrl !== $this->expectedHookUrl($app) => WebhookMode::polling(WebhookMode::MOVED, self::MOVED),
            default => WebhookMode::polling(WebhookMode::UNPROVEN, self::NO_DELIVERY),
        };
    }

    public function hasWebhook(#[\SensitiveParameter] GitHubAppCredentials $app): bool
    {
        return $app->isEnv() ? $app->webhookSecret !== null : $app->hookUrl !== null;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(#[\SensitiveParameter] GitHubAppCredentials $app, array $changes): void
    {
        $key = $this->key($app);
        $stored = Cache::get($key);

        Cache::forever($key, [...self::EVIDENCE, ...(is_array($stored) ? array_intersect_key($stored, self::EVIDENCE) : []), ...$changes]);
    }

    /**
     * @return array{received: ?CarbonImmutable, latest: ?array{status_code: int, status: string, delivered_at: CarbonImmutable}, sends_push: ?bool}
     */
    private function evidence(#[\SensitiveParameter] GitHubAppCredentials $app): array
    {
        $stored = Cache::get($this->key($app));
        $latest = is_array($stored) ? ($stored['latest'] ?? null) : null;
        $delivered = is_array($latest) ? $this->moment($latest['delivered_at'] ?? null) : null;
        $repointed = is_array($stored) ? $this->moment($stored['repointed_at'] ?? null) : null;

        if ($delivered !== null && $repointed !== null && $delivered->lessThan($repointed)) {
            $delivered = null;
        }

        return [
            'received' => is_array($stored) ? $this->moment($stored['received_at'] ?? null) : null,
            'latest' => $delivered !== null && is_int($latest['status_code'] ?? null) && is_string($latest['status'] ?? null)
                ? ['status_code' => $latest['status_code'], 'status' => $latest['status'], 'delivered_at' => $delivered]
                : null,
            'sends_push' => is_array($stored) && is_bool($stored['sends_push'] ?? null) ? $stored['sends_push'] : null,
        ];
    }

    private function moment(mixed $timestamp): ?CarbonImmutable
    {
        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp, date_default_timezone_get()) : null;
    }

    private function delivered(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode <= 399;
    }

    /**
     * @param  array{status_code: int, status: string, delivered_at: CarbonImmutable}  $latest
     */
    private function failure(array $latest): string
    {
        $status = trim($latest['status']);

        return "GitHub's last delivery here failed " . Formats::absolute($latest['delivered_at']) . ($status === '' ? '.' : ": {$status}.");
    }
}
