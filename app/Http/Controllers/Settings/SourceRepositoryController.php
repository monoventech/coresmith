<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Enums\SourceProviderType;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitHubLinkProofException;
use App\Exceptions\GitProviderException;
use App\Http\Controllers\Controller;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\LinkProof;
use App\Services\Git\GitHub\RepositoryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SourceRepositoryController extends Controller
{
    private const REPOSITORY_LIST = 'GitHub did not send the repository list. ';

    private const BRANCH_LIST = 'GitHub did not send the branch list. ';

    public function __construct(
        private readonly RepositoryCatalog $catalog,
        private readonly LinkProof $proof,
    ) {}

    public function index(Request $request, SourceProvider $sourceProvider): JsonResponse
    {
        $this->assertUsable($request, $sourceProvider);

        try {
            $catalog = $this->catalog->for($sourceProvider);
        } catch (GitProviderException $exception) {
            return $this->unavailable(self::REPOSITORY_LIST, $exception);
        }

        return response()->json([
            'repositories' => $catalog['repositories'],
            'total' => $catalog['total'],
            'truncated' => $catalog['truncated'],
        ]);
    }

    public function branches(Request $request, SourceProvider $sourceProvider, string $repositoryId): JsonResponse
    {
        $this->assertUsable($request, $sourceProvider);

        try {
            $repository = $this->catalog->find($sourceProvider, $repositoryId);
        } catch (GitProviderException $exception) {
            return $this->unavailable(self::REPOSITORY_LIST, $exception);
        }

        if ($repository === null) {
            return response()->json(['message' => RepositoryCatalog::NOT_VISIBLE], 404);
        }

        try {
            $this->proof->check($sourceProvider, (string) $repository['full_name']);
        } catch (GitHubLinkProofException $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 409);
        } catch (GitHubInstallationException $refusal) {
            return $refusal->kind === GitHubInstallationException::REPOSITORY
                ? response()->json(['message' => RepositoryCatalog::NOT_VISIBLE], 404)
                : $this->unavailable(self::BRANCH_LIST, $refusal);
        }

        try {
            $branches = $this->catalog->branches($sourceProvider, $repository);
        } catch (GitProviderException $exception) {
            return $this->unavailable(self::BRANCH_LIST, $exception);
        }

        return response()->json(['branches' => $branches, 'default' => $repository['default_branch']]);
    }

    private function assertUsable(Request $request, SourceProvider $sourceProvider): void
    {
        $team = $this->currentTeam();

        abort_unless($team !== null && $sourceProvider->team_id === $team->getKey(), 404);
        abort_unless($request->user()->hasTeamAbility($team, Ability::CreateApplication), 403);
        abort_unless($sourceProvider->type === SourceProviderType::GitHubApp && $sourceProvider->connected(), 404);
    }

    private function unavailable(string $sentence, GitProviderException $exception): JsonResponse
    {
        return response()->json(['message' => $sentence . $exception->getMessage()], 502);
    }
}
