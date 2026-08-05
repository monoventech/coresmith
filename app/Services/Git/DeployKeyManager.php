<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Application;
use App\Services\SafeName;
use App\Services\SSH\KeyPairGenerator;

final class DeployKeyManager
{
    public const KEY_DIR = '/etc/coresmith/deploy-keys';

    public function __construct(private readonly KeyPairGenerator $keys) {}

    public function ensure(Application $application): Application
    {
        if ($application->deploy_key_private !== null && $application->deploy_key_public !== null) {
            return $application;
        }

        $pair = $this->keys->generate();

        $application->forceFill([
            'deploy_key_private' => $pair->privateKey,
            'deploy_key_public' => $pair->publicKey,
        ])->save();

        return $application->refresh();
    }

    public function keyPath(Application $application): string
    {
        return self::KEY_DIR.'/'.SafeName::assertSlug($application->slug);
    }

    public function prepareDirectory(Application $application): string
    {
        $dir = escapeshellarg(self::KEY_DIR);
        $owner = escapeshellarg(SafeName::assertSystemUser($application->server->ssh_user));

        return "mkdir -p {$dir} && chown {$owner} {$dir} && chmod 700 {$dir}";
    }

    public function reserveFile(Application $application): string
    {
        return 'install -m 600 /dev/null '.escapeshellarg($this->keyPath($application));
    }

    /**
     * @return list<string>
     */
    public function installCommands(Application $application): array
    {
        return [$this->prepareDirectory($application), $this->reserveFile($application)];
    }

    public function removeCommand(Application $application): string
    {
        return 'rm -f '.escapeshellarg($this->keyPath($application));
    }

    public function sshCommand(Application $application): string
    {
        return 'ssh -i '.escapeshellarg($this->keyPath($application))
            .' -o IdentitiesOnly=yes'
            .' -o StrictHostKeyChecking=accept-new'
            .' -o UserKnownHostsFile='.escapeshellarg(self::KEY_DIR.'/known_hosts');
    }

    public function withKey(Application $application, string $gitCommand): string
    {
        return 'GIT_SSH_COMMAND='.escapeshellarg($this->sshCommand($application)).' '.$gitCommand;
    }

    public function usesDeployKey(Application $application): bool
    {
        $url = (string) $application->repository_url;

        return $url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://');
    }
}
