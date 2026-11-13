<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Enums\Ability;
use App\Enums\ApplicationType;
use App\Enums\SourceProviderType;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitHubLinkProofException;
use App\Exceptions\GitProviderException;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Rules\FillableEnvironment;
use App\Services\Detection\DetectionStore;
use App\Services\Detection\RepositoryRead;
use App\Services\Detection\StackChecks;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\LinkProof;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Git\RefusalReason;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreApplicationRequest extends FormRequest
{
    private const CONNECTION_UNAVAILABLE = "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings.";

    private const CONNECTION_SUSPENDED = 'GitHub has suspended this installation. Unsuspend it on GitHub to deploy again.';

    private const APP_GONE = 'That GitHub connection was made through an app Coresmith no longer has. Pick another one, or connect GitHub again in Git settings.';

    private const REPOSITORY_NOT_PICKED = 'Pick a repository from the list.';

    private ?SourceProvider $connection = null;

    /** @var array<string, mixed>|null */
    private ?array $repository = null;

    private ?RepositoryRead $checked = null;

    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && $this->user()->can('view', $server)
            && $this->user()->hasTeamAbility($server->team, Ability::CreateApplication);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $server = $this->route('server');

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'js_runtime' => ['nullable', Rule::in(['bun', 'node', 'deno'])],
            'framework' => ['nullable', 'string', 'max:64'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'repository_url' => ['nullable', 'string', 'max:2048', 'regex:' . SafeName::GIT_URL_PATTERN],
            'source_provider_id' => ['nullable', 'required_with:repository_id', 'string', Rule::exists('source_providers', 'id')->where('team_id', $server->team_id)->where('type', SourceProviderType::GitHubApp->value)->whereNull('deleted_at')],
            'repository_id' => ['nullable', 'required_with:source_provider_id', 'string', 'regex:/^[0-9]{1,20}\\z/'],
            'repository_branch' => ['required', 'string', 'max:255', 'regex:' . SafeName::BRANCH_PATTERN],
            'runtime_version' => ['nullable', 'string', Rule::in($this->runtimeLines())],
            'acknowledge_eol' => ['boolean'],
            'directory' => ['required', 'string', 'max:255', 'regex:' . SafeName::RELATIVE_PATH_PATTERN],
            'web_directory' => ['required', 'string', 'max:255', 'regex:' . SafeName::RELATIVE_PATH_PATTERN],
            'app_size' => ['required', Rule::in(['small', 'medium', 'large', 'custom'])],
            'run_migrations' => ['boolean'],
            'environment_id' => ['nullable', 'string', new FillableEnvironment($server?->team, $this->user())],
            'detection' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example app.example.com.',
            'repository_branch.regex' => 'That is not a branch name Git accepts. Use something like main or release/2.0.',
            'directory.regex' => 'Use a folder inside the repository, like / or apps/web.',
            'web_directory.regex' => 'Use a folder inside the app, like /public or dist.',
            'repository_url.regex' => 'Use an https:// URL for public repositories, or git@host:owner/repo.git for private ones.',
            'source_provider_id.required_with' => self::CONNECTION_UNAVAILABLE,
            'source_provider_id.string' => self::CONNECTION_UNAVAILABLE,
            'source_provider_id.exists' => self::CONNECTION_UNAVAILABLE,
            'repository_id.required_with' => self::REPOSITORY_NOT_PICKED,
            'repository_id.string' => self::REPOSITORY_NOT_PICKED,
            'repository_id.regex' => self::REPOSITORY_NOT_PICKED,
        ];
    }

    public function runtime(): ?string
    {
        $type = $this->input('type');
        $js = $this->input('js_runtime');

        return ApplicationType::tryFrom(is_string($type) ? $type : '')?->runtime(is_string($js) ? $js : null);
    }

    public function runtimeLine(): ?string
    {
        $line = $this->input('runtime_version');

        return is_string($line) && $line !== '' && $this->runtime() !== null ? $line : null;
    }

    public function isEndOfLife(string $runtime, string $line): bool
    {
        $data = app(Catalog::class)->line($runtime, $line);

        return $data !== null && app(RuntimeLifecycle::class)->status($runtime, $data['latest'])->isEol();
    }

    public function githubConnection(): ?SourceProvider
    {
        $id = $this->input('source_provider_id');
        $server = $this->route('server');

        if (!is_string($id) || $id === '' || !$server instanceof Server) {
            return null;
        }

        return $this->connection ??= SourceProvider::query()
            ->where('team_id', $server->team_id)
            ->where('type', SourceProviderType::GitHubApp->value)
            ->find($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function githubRepository(): ?array
    {
        return $this->repository;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $runtime = $this->runtime();
                $line = $this->runtimeLine();

                if ($runtime === null || $line === null || $this->boolean('acknowledge_eol') || $validator->errors()->has('runtime_version')) {
                    return;
                }

                if ($this->isEndOfLife($runtime, $line)) {
                    $validator->errors()->add('runtime_version', app(Catalog::class)->label($runtime) . " {$line} no longer gets security fixes. Confirm you want it, or pick another version.");
                }
            },
            function (Validator $validator): void {
                $this->resolveRepository($validator);
            },
            function (Validator $validator): void {
                $this->checkStack($validator);
            },
        ];
    }

    public function checkedRead(): ?RepositoryRead
    {
        return $this->checked;
    }

    public function hasRepository(): bool
    {
        return $this->repositoryKey() !== null;
    }

    private function checkStack(Validator $validator): void
    {
        $server = $this->route('server');
        $repository = $this->repositoryKey();

        if ($validator->errors()->isNotEmpty() || !$server instanceof Server || $repository === null) {
            return;
        }

        $read = app(DetectionStore::class)->find($this->input('detection'), $this->user(), $server, $repository, (string) $this->input('repository_branch'), (string) $this->input('directory', '/'));
        $field = $this->repository === null ? 'repository_url' : 'repository_id';

        if ($read === null) {
            return;
        }

        if ($read->facts === null) {
            if ($read->definitive) {
                $validator->errors()->add($field, (string) $read->failure);
            }

            return;
        }

        $line = $this->runtimeLine();
        $choice = new Application([
            'type' => $this->input('type'),
            'framework' => $this->input('framework'),
            'js_runtime' => $this->input('js_runtime'),
            'directory' => $this->input('directory', '/'),
            'web_directory' => $this->input('web_directory'),
            'runtime_pins' => $line === null ? null : [$this->runtime() => $line],
        ]);

        $blockers = app(StackChecks::class)->blockers($choice, $read->facts, fn(string $runtime): array => $read->installed($runtime));

        foreach ($blockers as $blocker) {
            $validator->errors()->add($blocker->field === 'repository' ? $field : $blocker->field, trim($blocker->reason . ' ' . $blocker->fix));
        }

        $this->checked = $blockers === [] ? $read : null;
    }

    private function repositoryKey(): ?string
    {
        $connection = $this->githubConnection();

        if ($connection !== null && $this->repository !== null) {
            return 'github:' . $connection->getKey() . ':' . $this->repository['id'];
        }

        $url = $this->input('repository_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function resolveRepository(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['source_provider_id', 'repository_id'])) {
            return;
        }

        $connection = $this->githubConnection();

        if ($connection === null) {
            return;
        }

        if (!$connection->connected()) {
            $validator->errors()->add('source_provider_id', $connection->suspended_at === null ? self::CONNECTION_UNAVAILABLE : self::CONNECTION_SUSPENDED);

            return;
        }

        if (app(GitHubApps::class)->for($connection) === null) {
            $validator->errors()->add('source_provider_id', self::APP_GONE);

            return;
        }

        try {
            $repository = app(RepositoryCatalog::class)->find($connection, $this->string('repository_id')->value());
        } catch (GitProviderException $refusal) {
            $validator->errors()->add(...$this->refusal($connection, $refusal));

            return;
        }

        if ($repository === null) {
            $validator->errors()->add('repository_id', RepositoryCatalog::NOT_VISIBLE);

            return;
        }

        try {
            app(LinkProof::class)->check($connection, (string) $repository['full_name']);
        } catch (GitHubLinkProofException $refusal) {
            $validator->errors()->add('repository_id', $refusal->getMessage());

            return;
        } catch (GitProviderException $refusal) {
            $validator->errors()->add(...$this->refusal($connection, $refusal));

            return;
        }

        $this->repository = $repository;
    }

    /**
     * @return array{string, string}
     */
    private function refusal(SourceProvider $connection, GitProviderException $refusal): array
    {
        $installation = $refusal instanceof GitHubInstallationException ? $refusal : null;

        $connection->markRefused($refusal);

        return match ($installation?->kind) {
            GitHubInstallationException::UNINSTALLED => ['source_provider_id', "Coresmith's GitHub app is no longer installed on {$connection->name}. Pick another connection, or connect GitHub again in Git settings."],
            GitHubInstallationException::SUSPENDED => ['source_provider_id', self::CONNECTION_SUSPENDED],
            GitHubInstallationException::REPOSITORY => ['repository_id', RepositoryCatalog::NOT_VISIBLE],
            GitHubInstallationException::REFUSED => ['source_provider_id', "GitHub would not give Coresmith a token for {$connection->name}. It said: " . RefusalReason::sentence((string) $installation?->reason) . ' Fix that on GitHub, then try again.'],
            default => ['repository_id', RepositoryCatalog::UNREACHABLE],
        };
    }

    /**
     * @return list<string>
     */
    private function runtimeLines(): array
    {
        $runtime = $this->runtime();

        return $runtime === null ? [] : array_column(app(Catalog::class)->lines($runtime), 'line');
    }
}
