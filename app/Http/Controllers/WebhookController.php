<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DeployApplication;
use App\Models\Deployment;
use App\Models\WebhookEndpoint;
use App\Services\Webhook\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController extends Controller
{
    public function deploy(Request $request, WebhookEndpoint $webhook, SignatureVerifier $signatures): JsonResponse
    {
        if (!$webhook->active) {
            return response()->json(['message' => 'This webhook is disabled.'], 404);
        }

        if (!$signatures->verify($request, (string) $webhook->provider, (string) $webhook->secret)) {
            activity()
                ->performedOn($webhook)
                ->withProperties(['provider' => $webhook->provider, 'ip' => $request->ip()])
                ->log('webhook_signature_rejected');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $application = $webhook->application;

        if ($application === null) {
            return response()->json(['message' => 'This webhook has no application.'], 404);
        }

        $branch = $signatures->branchFromPayload($request);

        if ($branch !== null && $branch !== $application->repository_branch) {
            return response()->json(['message' => 'Branch ignored.'], 202);
        }

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'commit_hash' => $request->input('after') ?? $request->input('checkout_sha'),
            'commit_message' => $request->input('head_commit.message'),
        ]);

        DeployApplication::dispatch($deployment, now()->format('YmdHis'));

        return response()->json(['message' => 'Deployment queued.'], 202);
    }
}
