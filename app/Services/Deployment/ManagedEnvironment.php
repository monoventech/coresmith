<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\Panel\Reverb;
use App\Services\Panel\SmartToggles;

final class ManagedEnvironment
{
    public function __construct(
        private readonly EnvironmentFile $environment,
        private readonly Reverb $reverb,
    ) {}

    /**
     * @param  array<string, mixed>|null  $stack
     * @return array<string, string>
     */
    public function values(Application $application, ?array $stack = null): array
    {
        $stack ??= (array) $application->stack;

        return array_diff_key([...$this->reverbValues($application, $stack), ...$this->ssrValues($application, $stack)], $this->environment->parse((string) $application->env_variables));
    }

    /**
     * @param  array<string, mixed>  $stack
     * @return array<string, string>
     */
    private function ssrValues(Application $application, array $stack): array
    {
        $toggles = app(SmartToggles::class);

        if (!$toggles->hasInertia($stack)) {
            return [];
        }

        if (!$toggles->ssrOn($application) || $application->ssr_port === null || !$toggles->ssrBuilt($stack)) {
            return ['INERTIA_SSR_ENABLED' => 'false'];
        }

        return [
            'INERTIA_SSR_ENABLED' => 'true',
            'INERTIA_SSR_URL' => 'http://127.0.0.1:' . $application->ssr_port,
        ];
    }

    /**
     * @param  array<string, mixed>  $stack
     * @return array<string, string>
     */
    private function reverbValues(Application $application, array $stack): array
    {
        $credentials = $this->reverb->credentials($application);

        if (!$this->reverb->ready($application, $stack) || $credentials === null) {
            return [];
        }

        $domain = (string) $application->domain;

        return [
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => (string) $this->reverb->port($application),
            'REVERB_HOST' => $domain,
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
            'VITE_REVERB_HOST' => $domain,
            'VITE_REVERB_PORT' => '443',
            'VITE_REVERB_SCHEME' => 'https',
            'VITE_REVERB_APP_KEY' => $credentials['key'],
        ];
    }
}
