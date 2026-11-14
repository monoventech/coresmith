<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Detection\Blocker;
use App\Services\Runtime\RepositoryFacts;

final readonly class GoChecks
{
    public function __construct(private GoDetection $detection) {}

    /**
     * @return list<Blocker>
     */
    public function checks(?string $mainPackage, string $framework, RepositoryFacts $facts): array
    {
        $module = GoModule::from($facts);

        if ($module === null) {
            return [new Blocker('repository', "There is no go.mod {$facts->at()}, so Coresmith can't build the app as a Go module.", 'Run go mod init with the module path, then commit go.mod and go.sum.')];
        }

        $source = GoSource::from($facts);
        $mains = $source->mains();

        if ($module->requires('encore.dev') !== null) {
            return [new Blocker('framework', "This is an Encore app, which builds only with Encore's own compiler, and Coresmith builds Go apps with go build.", "Coresmith can't deploy Encore apps.")];
        }

        if ($mains === [] && $module->requires('github.com/revel/revel') !== null) {
            return [new Blocker('framework', "This is a Revel app. Revel writes the app's main package during revel build, and Coresmith builds Go apps with go build.", 'Add a main package that starts the app, then deploy.')];
        }

        $web = $mainPackage ?? $this->detection->web($source, $mains, $facts)[0];

        return array_values(array_filter([
            $web === null ? null : $this->hardcoded($source, $web, $framework),
            $framework === 'beego' ? $this->configPort($facts, 'conf/app.conf', '/^\s*httpport\s*=\s*(\d+)\s*$/mi', 'httpport = %s', 'Change it to httpport = ${PORT||%s}, which Beego reads from the environment.') : null,
            $framework === 'go-zero' ? $this->goZeroPort($facts) : null,
        ]));
    }

    private function hardcoded(GoSource $source, string $web, string $framework): ?Blocker
    {
        $lines = $source->hardcoded($web);

        if ($lines === [] || $framework === 'buffalo' || $source->readsPort() || $source->variables() !== [] || $source->flag($web) !== null || $source->mentionsAddress()) {
            return null;
        }

        [$file, $number, $text] = $lines[0];
        preg_match('/"(?:[A-Za-z0-9.\-]*|\[[0-9A-Fa-f:]*\])?:(\d{2,5})"/', $text, $port);

        return new Blocker('repository', "{$file} line {$number} listens on port {$port[1]}, and Coresmith gives each app its own port in the PORT environment variable.", "Listen on \":\"+os.Getenv(\"PORT\") there instead of port {$port[1]}.");
    }

    private function configPort(RepositoryFacts $facts, string $file, string $pattern, string $setting, string $fix): ?Blocker
    {
        if (preg_match($pattern, (string) $facts->file($file), $matches) !== 1) {
            return null;
        }

        return new Blocker('repository', "{$file} sets " . sprintf($setting, $matches[1]) . ', and Coresmith gives each app its own port in the PORT environment variable.', sprintf($fix, $matches[1]));
    }

    private function goZeroPort(RepositoryFacts $facts): ?Blocker
    {
        foreach ([...$facts->matching('etc/*.yaml'), ...$facts->matching('etc/*.yml')] as $file => $content) {
            if (preg_match('/^Port:\s*(\d+)\s*$/m', $content, $matches) === 1) {
                return new Blocker('repository', "{$file} sets Port: {$matches[1]}, and Coresmith gives each app its own port in the PORT environment variable.", 'Set Port: ${PORT}, and load the file with conf.MustLoad(*configFile, &c, conf.UseEnv()) so go-zero reads it from the environment.');
            }
        }

        return null;
    }
}
