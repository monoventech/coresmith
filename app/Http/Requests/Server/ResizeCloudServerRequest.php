<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\CloudFailure;
use App\Exceptions\CloudProviderException;
use App\Models\Server;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\ResizeOptions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResizeCloudServerRequest extends FormRequest
{
    private ?string $unchecked = null;

    public function authorize(): bool
    {
        $server = $this->route('server');

        abort_unless($server instanceof Server && ResizeOptions::resizable($server), 404);

        return $this->user()->can('update', $server);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $offered = $this->offered();

        return [
            'size' => ['required', 'string', $this->unchecked === null ? Rule::in($offered) : fn(string $attribute, mixed $value, Closure $fail) => $fail($this->unchecked)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $server = $this->route('server');
        $message = "That size can't be used for " . ($server instanceof Server ? $server->name : 'this server') . '. Pick one from the list.';

        return [
            'size.required' => $message,
            'size.string' => $message,
            'size.in' => $message,
        ];
    }

    public function size(): string
    {
        return (string) $this->validated('size');
    }

    /**
     * @return list<string>
     */
    private function offered(): array
    {
        $server = $this->route('server');

        if (!$server instanceof Server) {
            return [];
        }

        try {
            [, $sizes] = app(ResizeOptions::class)->for($server);
        } catch (CloudProviderException $e) {
            if ($e->failure === CloudFailure::TokenRejected) {
                $server->cloudProvider?->forceFill(['token_failed_at' => now()])->save();
            }

            $this->unchecked = "Coresmith couldn't check that size with {$e->provider->label()}. {$e->getMessage()}";

            return [];
        }

        return $sizes->map(fn(CloudSize $size): string => $size->id)->values()->all();
    }
}
