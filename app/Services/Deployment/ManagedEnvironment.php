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
     * @return array<string, string>
     */
    public function values(Application $application): array
    {
        return array_diff_key([...$this->reverbValues($application), ...$this->ssrValues($application)], $this->environment->parse((string) $application->env_variables));
    }

    /**
     * @return array<string, string>
     */
    private function ssrValues(Application $application): array
    {
        if (!app(SmartToggles::class)->ssrOn($application) || $application->ssr_port === null) {
            return [];
        }

        return [
            'INERTIA_SSR_ENABLED' => 'true',
            'INERTIA_SSR_URL' => 'http://127.0.0.1:' . $application->ssr_port,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function reverbValues(Application $application): array
    {
        $credentials = $this->reverb->credentials($application);

        if (!$this->reverb->ready($application) || $credentials === null) {
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
