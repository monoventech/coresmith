<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DeployApplication;
use App\Models\Deployment;
use App\Models\WebhookEndpoint;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Webhook\SignatureVerifier;
use App\Services\Webhook\WebhookDeliveries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WebhookController extends Controller
{
    private const DELIVERY_HEADERS = ['X-GitHub-Delivery', 'X-Gitlab-Event-UUID', 'X-Request-UUID', 'X-Coresmith-Delivery'];

    private const EVENT_HEADERS = ['X-GitHub-Event', 'X-Gitlab-Event', 'X-Event-Key', 'X-Coresmith-Event'];

    public function __construct(private readonly WebhookDeliveries $deliveries) {}

    public function deploy(Request $request, WebhookEndpoint $webhook, SignatureVerifier $signatures): JsonResponse
    {
        if (!$webhook->active) {
            return response()->json(['message' => 'This webhook is disabled.'], 404);
        }

        if (!$signatures->verify($request, (string) $webhook->provider, (string) $webhook->secret)) {
            $this->deliveries->signatureRejected($request, $webhook, ['provider' => $webhook->provider]);

            return $this->respond($webhook, $request, 401, 'Invalid signature.');
        }

        $delivery = $this->deliveries->header($request, self::DELIVERY_HEADERS);

        if ($delivery !== null && !$this->deliveries->claim('webhook-delivery:' . $webhook->getKey(), $delivery)) {
            return $this->respond($webhook, $request, 409, 'This delivery was already processed.');
        }

        $application = $webhook->application;

        if ($application === null) {
            return $this->respond($webhook, $request, 404, 'This webhook has no application.');
        }

        $event = $webhook->provider === 'github' ? $request->header('X-GitHub-Event') : null;

        if ($event === 'ping') {
            return $this->respond($webhook, $request, 200, 'pong');
        }

        if ($event !== null && $event !== 'push') {
            return $this->respond($webhook, $request, 202, 'Event ignored.');
        }

        $branch = $signatures->branchFromPayload($request);

        if ($branch !== null && $branch !== $application->repository_branch) {
            return $this->respond($webhook, $request, 202, 'Branch ignored.');
        }

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'trigger' => 'push',
            'requested_commit' => $this->requestedCommit($request),
            'commit_message' => $this->subject($request->input('head_commit.message')),
        ]);

        DeployApplication::dispatch($deployment, now()->format('Ymd-His'));

        return $this->respond($webhook, $request, 202, 'Deployment queued.');
    }

    private function requestedCommit(Request $request): ?string
    {
        $head = $request->input('after') ?? $request->input('checkout_sha');

        return is_string($head) && preg_match(AutoDeploy::COMMIT, $head) === 1 ? $head : null;
    }

    private function subject(mixed $message): ?string
    {
        return is_string($message) ? Str::limit(trim(Str::before($message, "\n")), 250, '') : null;
    }

    private function respond(WebhookEndpoint $webhook, Request $request, int $status, string $message): JsonResponse
    {
        $webhook->forceFill([
            'last_delivery_id' => $this->deliveries->header($request, self::DELIVERY_HEADERS),
            'last_event' => $this->deliveries->header($request, self::EVENT_HEADERS, '/^[A-Za-z0-9:._ -]{1,64}$/'),
            'last_status' => $status,
            'last_delivered_at' => now(),
        ])->save();

        return response()->json(['message' => $message], $status);
    }
}
