<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Models\Application;
use App\Models\SourceProvider;
use App\Services\SafeName;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

final class UpdateApplicationSetupRequest extends StoreApplicationRequest
{
    public const LINKED = "This app's repository comes from its GitHub connection, so it can't be changed here.";

    public function authorize(): bool
    {
        $application = $this->route('application');

        return $application instanceof Application && $this->user()->can('update', $application);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['name', 'domain', 'source_provider_id', 'repository_id', 'project_id', 'tags', 'tags.*']),
            'repository_url' => $this->linked() ? ['missing'] : ['required', 'string', 'max:2048', 'regex:' . SafeName::GIT_URL_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'repository_url.missing' => self::LINKED];
    }

    public function linked(): bool
    {
        return $this->application()?->source_provider_id !== null;
    }

    public function githubConnection(): ?SourceProvider
    {
        $application = $this->application();

        return $application?->source_provider_id === null ? null : SourceProvider::query()->find($application->source_provider_id);
    }

    protected function resolveRepository(Validator $validator): void
    {
        $application = $this->application();

        if ($application === null || !$this->linked()) {
            return;
        }

        $this->repository = ['id' => (string) $application->repository_id, 'full_name' => SetupSource::fullName((string) $application->repository_url)];
    }

    private function application(): ?Application
    {
        $application = $this->route('application');

        return $application instanceof Application ? $application : null;
    }
}
