<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\User;
use App\Services\Detection\DetectionStore;
use App\Services\Detection\RepositoryAccess;
use App\Services\Detection\RepositoryReader;
use App\Services\Detection\StackDetector;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\SafeName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StackDetectionController extends Controller
{
    public function show(Request $request, Server $server, RepositoryReader $reader, StackDetector $detector, DetectionStore $store): JsonResponse
    {
        $this->authorize('view', $server);
        $this->authorize('create', Application::class);

        $validated = $request->validate([
            'repository_url' => ['nullable', 'required_without:repository_id', 'string', 'max:2048', 'regex:' . SafeName::GIT_URL_PATTERN],
            'source_provider_id' => ['nullable', 'required_with:repository_id', 'string'],
            'repository_id' => ['nullable', 'string', 'regex:/^[0-9]{1,20}\z/'],
            'repository_branch' => ['required', 'string', 'max:255', 'regex:' . SafeName::BRANCH_PATTERN],
            'directory' => ['nullable', 'string', 'max:255', 'regex:' . SafeName::RELATIVE_PATH_PATTERN],
        ]);

        $access = $this->access($request->user(), $server, $validated);

        if (is_string($access)) {
            return response()->json(['state' => 'failed', 'failure' => $access, 'definitive' => true]);
        }

        if ($access === null) {
            return response()->json(['state' => 'needs_key']);
        }

        if ($server->status !== ServerStatus::Active) {
            return response()->json(['state' => 'server_not_ready', 'server' => $server->name]);
        }

        $branch = (string) $validated['repository_branch'];
        $directory = (string) ($validated['directory'] ?? '/');
        $read = $reader->read($server, $access, $branch, $directory);
        $id = $store->put($request->user(), $server, $access, $branch, $directory, $read);

        if ($read->facts === null) {
            return response()->json(['id' => $id, 'state' => 'failed', 'failure' => $read->failure, 'definitive' => $read->definitive]);
        }

        $detected = $detector->detect($read->facts, fn(string $runtime): array => $read->installed($runtime), fn(string $version): ?array => $read->extensions($version), $read->arch)->toArray();

        if ($read->facts->complete && $read->folders !== [] && trim($directory, '/') === '' && isset($detected['unsettled']['type'])) {
            $detected['unsettled']['type'] .= ' Pick the folder the app is in.';
        }

        return response()->json([
            'id' => $id,
            'state' => 'read',
            'commit' => $read->commit,
            ...$detected,
            'folders' => $read->folders,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function access(User $user, Server $server, array $validated): RepositoryAccess|string|null
    {
        $repositoryId = $validated['repository_id'] ?? null;

        if (is_string($repositoryId) && $repositoryId !== '') {
            $connection = SourceProvider::query()
                ->where('team_id', $server->team_id)
                ->where('type', SourceProviderType::GitHubApp->value)
                ->find($validated['source_provider_id'] ?? null);

            if (!$connection instanceof SourceProvider || !$connection->connected() || $server->team === null || !$connection->usableBy($user, $server->team)) {
                return "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings.";
            }

            try {
                $repository = app(RepositoryCatalog::class)->find($connection, $repositoryId);
            } catch (GitProviderException) {
                return RepositoryCatalog::UNREACHABLE;
            }

            return $repository === null ? RepositoryCatalog::NOT_VISIBLE : RepositoryAccess::github($connection, (string) $repository['id'], (string) $repository['full_name']);
        }

        $url = (string) ($validated['repository_url'] ?? '');

        return str_starts_with($url, 'https://') ? RepositoryAccess::public($url) : null;
    }
}
