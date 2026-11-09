<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\GitHubApp;
use App\Models\TeamSsoConnection;
use App\Services\Address\AddressChange;
use App\Services\Address\InstallationAddress;
use App\Services\Sso\SignIn;

final class AddressPresenter
{
    public function __construct(private readonly InstallationAddress $address) {}

    /**
     * @return array{host: string, url: string, certificate: string, managed: bool, change: ?array<string, mixed>, follow: ?array{github: ?string, sso: ?string}}|null
     */
    public function present(): ?array
    {
        if (Edition::current()->isCloud()) {
            return null;
        }

        $host = $this->address->host();
        $url = rtrim((string) config('app.url'), '/');
        $change = AddressChange::current();

        return [
            'host' => $host,
            'url' => $url,
            'certificate' => InstallationAddress::certificate($host)->value,
            'managed' => $this->address->managed(),
            'change' => $change,
            'follow' => ($change['state'] ?? null) !== 'done' ? null : [
                'github' => GitHubApp::query()->exists() ? route('source-providers.index') : null,
                'sso' => TeamSsoConnection::query()->exists() ? $url . SignIn::CALLBACK_PATH : null,
            ],
        ];
    }
}
