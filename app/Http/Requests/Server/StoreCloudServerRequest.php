<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Server;

use App\Enums\Ability;
use App\Enums\CloudFailure;
use App\Exceptions\CloudProviderException;
use App\Http\Controllers\ServerController;
use App\Models\CloudProvider;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudSize;
use App\Services\Panel\CloudPresenter;
use App\Services\Teams\TeamContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

final class StoreCloudServerRequest extends FormRequest
{
    private const NAME = 'Use 3 to 63 lowercase letters, digits and single hyphens, like outpost-02.';

    private ?CloudProvider $resolvedAccount = null;

    private ?CloudSize $resolvedSize = null;

    private ?CloudImage $resolvedImage = null;

    private ?string $resolvedRegionLabel = null;

    public function authorize(): bool
    {
        $team = app(TeamContext::class)->team();

        return $team !== null && $this->user()->hasTeamAbility($team, Ability::CreateServer);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $identifier = ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'];

        return [
            'cloud_provider_id' => ['required', 'string', Rule::exists('cloud_providers', 'id')->where('team_id', app(TeamContext::class)->team()?->getKey())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'regex:/^(?=.{3,63}$)[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'region' => $identifier,
            'size' => $identifier,
            'image' => $identifier,
            'environment' => ['nullable', Rule::in(ServerController::ENVIRONMENTS)],
            'return_to' => ['nullable', Rule::in(['provision', 'setup'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cloud_provider_id.required' => 'Pick a connected provider account.',
            'cloud_provider_id.exists' => 'Pick a connected provider account.',
            'name.required' => self::NAME,
            'name.regex' => self::NAME,
            'environment.in' => ServerController::PICK_ENVIRONMENT,
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $account = CloudProvider::query()->find($this->input('cloud_provider_id'));

            if ($account === null) {
                $validator->errors()->add('cloud_provider_id', 'Pick a connected provider account.');

                return;
            }

            $label = $account->type->label();

            if ($account->isRefused()) {
                $validator->errors()->add('cloud_provider_id', CloudPresenter::refusal($account));

                return;
            }

            try {
                $catalog = app(CloudCatalog::class)->for($account);
            } catch (CloudProviderException $e) {
                if ($e->failure === CloudFailure::TokenRejected) {
                    $account->forceFill(['token_failed_at' => now()])->save();
                }

                $validator->errors()->add('cloud_provider_id', "Coresmith couldn't check that with {$label}. {$e->getMessage()}");

                return;
            }

            $regionId = (string) $this->input('region');
            $region = $catalog->region($regionId);

            if ($region === null) {
                $validator->errors()->add('region', "That region isn't offered any more. Pick another.");

                return;
            }

            $size = $catalog->sizesIn($regionId)->first(fn(CloudSize $size): bool => $size->id === $this->input('size'));

            if ($size === null) {
                $validator->errors()->add('size', "That size isn't offered in {$regionId} any more. Pick another.");

                return;
            }

            $image = $catalog->image((string) $this->input('image'));

            if ($image === null || !$image->supports($size->architecture)) {
                $validator->errors()->add('image', "That image doesn't run on {$size->id}. Pick another.");

                return;
            }

            [$this->resolvedAccount, $this->resolvedSize, $this->resolvedImage, $this->resolvedRegionLabel] = [$account, $size, $image, $region->label];
        }];
    }

    public function account(): CloudProvider
    {
        return $this->resolvedAccount ?? throw new LogicException('The account is resolved during validation.');
    }

    public function size(): CloudSize
    {
        return $this->resolvedSize ?? throw new LogicException('The size is resolved during validation.');
    }

    public function cloudImage(): CloudImage
    {
        return $this->resolvedImage ?? throw new LogicException('The image is resolved during validation.');
    }

    public function regionLabel(): string
    {
        return $this->resolvedRegionLabel ?? throw new LogicException('The region is resolved during validation.');
    }
}
