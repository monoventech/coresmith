<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GitHubApp;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubEventHandler;
use App\Services\Webhook\SignatureVerifier;
use App\Services\Webhook\WebhookDeliveries;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubApps $apps,
        private readonly SignatureVerifier $signatures,
        private readonly WebhookDeliveries $deliveries,
        private readonly GitHubAppHealth $health,
        private readonly GitHubEventHandler $events,
    ) {}

    public function environment(Request $request): JsonResponse
    {
        $app = $this->apps->env();

        if ($app === null) {
            return response()->json(['message' => 'Coresmith has no GitHub App.'], 404);
        }

        return $this->handle($request, $app, null);
    }

    public function registered(Request $request, GitHubApp $githubApp): JsonResponse
    {
        return $this->handle($request, $this->apps->fromModel($githubApp), $githubApp);
    }

    private function handle(Request $request, #[\SensitiveParameter] GitHubAppCredentials $app, ?GitHubApp $model): JsonResponse
    {
        $secret = $app->webhookSecret;

        if ($secret === null || trim($secret) === '') {
            return response()->json(['message' => 'This app has no webhook.'], 404);
        }

        if (!$this->signatures->verify($request, 'github', $secret)) {
            $this->deliveries->signatureRejected($request, $model, ['app' => $app->key]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $delivery = $this->deliveries->header($request, ['X-GitHub-Delivery']);
        $event = $this->deliveries->header($request, ['X-GitHub-Event']);
        $scope = 'github-delivery:' . $app->key;

        $this->health->received($app, (string) $event, $delivery);

        if ($delivery !== null && !$this->deliveries->claim($scope, $delivery)) {
            return response()->json(['message' => 'This delivery was already processed.'], 409);
        }

        $payload = json_decode($request->getContent(), true);

        try {
            return $this->process($app, $event, is_array($payload) ? $payload : [], $delivery);
        } catch (Throwable $failure) {
            if ($delivery !== null) {
                $this->deliveries->release($scope, $delivery);
            }

            throw $failure;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(#[\SensitiveParameter] GitHubAppCredentials $app, ?string $event, array $payload, ?string $delivery): JsonResponse
    {
        return match ($event) {
            'ping' => response()->json(['message' => 'pong']),
            'push' => $this->accepted(fn() => $this->events->push($app, $payload, $delivery), 'Push received.'),
            'installation' => $this->accepted(fn() => $this->events->installation($app, $payload), 'Installation updated.'),
            'installation_repositories' => $this->accepted(fn() => $this->events->installationRepositories($app, $payload), 'Repositories updated.'),
            default => response()->json(['message' => 'Event ignored.'], 202),
        };
    }

    private function accepted(Closure $handle, string $message): JsonResponse
    {
        $handle();

        return response()->json(['message' => $message], 202);
    }
}
