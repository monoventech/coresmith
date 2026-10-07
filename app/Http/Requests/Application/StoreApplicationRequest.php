<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Enums\Ability;
use App\Enums\ApplicationType;
use App\Models\Server;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;
use App\Services\SafeName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof Server
            && $this->user()->hasTeamAbility($server->team, Ability::CreateApplication);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ApplicationType::class)],
            'js_runtime' => ['nullable', Rule::in(['bun', 'node', 'deno'])],
            'framework' => ['nullable', 'string', 'max:64'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:' . SafeName::DOMAIN_PATTERN],
            'repository_url' => ['nullable', 'string', 'max:2048', 'regex:/^(https:\\/\\/[^\\s]+|git@[A-Za-z0-9.-]+:[^\\s]+)$/'],
            'repository_branch' => ['required', 'string', 'max:255'],
            'runtime_version' => ['nullable', 'string', Rule::in($this->runtimeLines())],
            'acknowledge_eol' => ['boolean'],
            'directory' => ['required', 'string', 'max:255'],
            'web_directory' => ['required', 'string', 'max:255'],
            'app_size' => ['required', Rule::in(['small', 'medium', 'large', 'custom'])],
            'run_migrations' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain name, for example app.example.com.',
            'repository_url.regex' => 'Use an https:// URL for public repositories, or git@host:owner/repo.git for private ones.',
        ];
    }

    public function runtime(): ?string
    {
        return match ($this->input('type')) {
            'php' => 'php',
            'ruby' => 'ruby',
            'js' => match ($this->input('js_runtime')) {
                'node' => 'nodejs',
                'deno' => 'deno',
                default => 'bun',
            },
            default => null,
        };
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
        ];
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
