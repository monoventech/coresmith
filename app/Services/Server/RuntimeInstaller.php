<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Server;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RuntimeBinaries;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Cache;

final readonly class RuntimeInstaller
{
    public const SECONDS = 2700;

    public function __construct(private AsdfManager $asdf, private RuntimeBinaries $binaries, private Catalog $catalog) {}

    /**
     * @param  callable(string): void  $note
     */
    public function install(SSHConnection $connection, Server $server, string $runtime, string $version, callable $note): void
    {
        Cache::lock('runtime-install:' . $server->getKey() . ':' . $runtime . ':' . $version, self::SECONDS)
            ->block(self::SECONDS - 60, function () use ($connection, $server, $runtime, $version, $note): void {
                if ($this->asdf->isInstalled($connection, $runtime, $version) && !$this->binaries->runtimeInstalled($connection, $runtime, $version)) {
                    $note($this->catalog->label($runtime) . " {$version} was left partly installed, so it is removed and installed again");
                    $this->asdf->uninstall($connection, $runtime, $version);
                }

                $this->asdf->install($connection, $server, $runtime, $version);

                foreach ($this->binaries->ensure($connection, $runtime, $version) as $line) {
                    $note($line);
                }
            });

        $this->record($server, $runtime, $version);
    }

    private function record(Server $server, string $runtime, string $version): void
    {
        $server->refresh();

        $installed = (array) $server->installed_runtimes;
        $versions = array_values(array_unique([...array_map('strval', (array) ($installed[$runtime] ?? [])), $version]));
        usort($versions, 'version_compare');
        $installed[$runtime] = $versions;

        $server->forceFill(['installed_runtimes' => $installed])->save();
    }
}
