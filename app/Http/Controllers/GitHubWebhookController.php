<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GitHubApp;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Webhook\SignatureVerifier;
use App\Services\Webhook\WebhookDeliveries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubApps $apps,
        private readonly SignatureVerifier $signatures,
        private readonly WebhookDeliveries $deliveries,
    ) {}

    public function environment(Request $request): JsonResponse
    {
        $app = $this->apps->env();

        if ($app === null) {
            return response()->json(['message' => 'This panel has no GitHub App.'], 404);
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

        if ($delivery !== null && !$this->deliveries->claim('github-delivery:' . $app->key, $delivery)) {
            return response()->json(['message' => 'This delivery was already processed.'], 409);
        }

        return match ($event) {
            'ping' => response()->json(['message' => 'pong']),
            default => response()->json(['message' => 'Event ignored.'], 202),
        };
    }
}
