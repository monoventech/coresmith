<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

use App\Models\Application;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateApplicationRuntimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $application instanceof Application && $this->user()?->can('update', $application) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $runtime = $this->runtime();

        return [
            'runtime_version' => ['nullable', 'string', Rule::in($runtime === null ? [] : array_column(app(Catalog::class)->lines($runtime), 'line'))],
            'acknowledge_eol' => ['boolean'],
        ];
    }

    public function runtime(): ?string
    {
        $application = $this->route('application');

        return $application instanceof Application ? $application->type?->runtime($application->js_runtime) : null;
    }

    public function line(): ?string
    {
        $line = $this->input('runtime_version');

        return is_string($line) && $line !== '' ? $line : null;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $runtime = $this->runtime();
                $line = $this->line();

                if ($runtime === null || $line === null || $this->boolean('acknowledge_eol') || $validator->errors()->has('runtime_version')) {
                    return;
                }

                $data = app(Catalog::class)->line($runtime, $line);

                if ($data !== null && app(RuntimeLifecycle::class)->status($runtime, $data['latest'])->isEol()) {
                    $validator->errors()->add('runtime_version', app(Catalog::class)->label($runtime) . " {$line} no longer gets security fixes. Confirm you want it, or pick another version.");
                }
            },
        ];
    }
}
