<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Models\Application;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\NativeGoTools;
use App\Services\Runtime\NativeTools;
use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;

final readonly class Go implements Language
{
    public const BIN = '.coresmith/bin';

    public function __construct(private AsdfManager $asdf, private JsCommands $js, private GoDetection $detection, private GoChecks $checks) {}

    public function install(Application $application, Stack $stack): array
    {
        $tooling = GoTooling::fromArray($stack->languageTooling);
        $go = $this->go($stack);

        if ($tooling === null || $go === null) {
            return [];
        }

        return [...($tooling->vendored ? [] : ["{$go} mod download"]), ...$this->js->install($stack)];
    }

    public function build(Application $application, Stack $stack): array
    {
        $tooling = GoTooling::fromArray($stack->languageTooling);
        $go = $this->go($stack);

        if ($tooling === null || $go === null) {
            return [];
        }

        $templ = match ($tooling->templ) {
            null => [],
            'tool' => ["{$go} tool templ generate"],
            default => ["{$go} run github.com/a-h/templ/cmd/templ@{$tooling->templ} generate"],
        };

        $binaries = array_map(
            fn(string $package, string $binary): string => "{$go} build -trimpath -o " . self::BIN . "/{$binary} " . escapeshellarg($package === '.' ? '.' : './' . $package),
            array_keys($tooling->mains),
            $tooling->mains,
        );

        return [...$this->js->build($application, $stack), ...$templ, ...$binaries];
    }

    public function migrations(Application $application, Stack $stack): array
    {
        $tooling = GoTooling::fromArray($stack->languageTooling);
        $go = $this->go($stack);
        $environment = (string) $application->env_variables;

        if ($tooling?->goose === null || $go === null || preg_match('/^GOOSE_DBSTRING=\S/m', $environment) !== 1 || preg_match('/^GOOSE_DRIVER=\S/m', $environment) !== 1) {
            return [];
        }

        return ["{$go} run github.com/pressly/goose/v3/cmd/goose@{$tooling->goose} -dir \"\${GOOSE_MIGRATION_DIR:-migrations}\" up"];
    }

    public function releaseChecks(Application $application, ReleasePaths $paths, string $release): array
    {
        $binary = $this->saved($application)?->webBinary();

        return $binary === null ? [] : [self::BIN . "/{$binary}" => 'test -x ' . escapeshellarg($paths->appDirectory($release) . '/' . self::BIN . "/{$binary}")];
    }

    public function webCommand(Application $application): ?string
    {
        $tooling = $this->saved($application);
        $binary = $tooling?->webBinary();

        if ($tooling === null || $binary === null) {
            return null;
        }

        $paths = new ReleasePaths($application);

        return $paths->appDirectory($paths->current()) . '/' . self::BIN . "/{$binary}" . ($tooling->flag === null ? '' : ' ' . $tooling->flag);
    }

    public function usesFpm(): bool
    {
        return false;
    }

    public function route(Application $application): Route
    {
        return $this->saved($application)?->grpc === true ? Route::ReverseProxyHttp2 : Route::ReverseProxy;
    }

    public function storageSeed(): StorageSeed
    {
        return StorageSeed::None;
    }

    public function initialEnvironment(?string $framework): array
    {
        return [];
    }

    public function processEnvironment(Application $application): array
    {
        return GoFrameworks::environment((string) $application->framework);
    }

    public function listenEnvironment(Application $application): array
    {
        $port = (string) $application->base_port;
        $listen = $this->saved($application)?->listen ?? [];

        return [
            'PORT' => $port,
            'HOST' => '127.0.0.1',
            ...array_map(fn(string $kind): string => $kind === 'address' ? "127.0.0.1:{$port}" : $port, $listen),
        ];
    }

    public function detect(RepositoryFacts $facts): ?array
    {
        return $this->detection->detect($facts);
    }

    public function checks(Application $choice, string $framework, RepositoryFacts $facts, ?string $arch): array
    {
        $main = $choice->main_package;

        return $this->checks->checks(is_string($main) && $main !== '' ? $main : null, $framework, $facts);
    }

    public function versionDoubt(RepositoryFacts $facts, ?ResolvedRuntime $runtime): ?string
    {
        return null;
    }

    public function cronExample(Application $application): string
    {
        $tooling = $this->saved($application);
        $others = array_values(array_diff_key($tooling?->mains ?? [], [(string) $tooling?->web => true]));

        return $others[0] ?? 'go run ./cmd/cleanup';
    }

    public function tooling(Application $application, RepositoryFacts $facts): array
    {
        $module = GoModule::from($facts);
        $source = GoSource::from($facts);
        $chosen = is_string($application->main_package) && preg_match(GoTooling::PACKAGE, $application->main_package) === 1 && !str_contains($application->main_package, '..') ? $application->main_package : null;
        $mains = $source->mains();

        if ($chosen !== null && !in_array($chosen, $mains, true)) {
            $mains[] = $chosen;
        }

        $web = $chosen ?? $this->detection->web($source, $mains, $facts)[0];
        $templ = $module?->hasTool('github.com/a-h/templ/cmd/templ') === true ? 'tool' : $module?->requires('github.com/a-h/templ');

        return (new GoTooling(
            GoTooling::binaries($mains, $module?->name() ?? 'app'),
            $web,
            $facts->exists('vendor/modules.txt'),
            $templ,
            $source->variables(),
            $web === null ? null : $source->flag($web),
            $web !== null && $source->grpcOnly($web),
            $module?->requires('github.com/pressly/goose'),
        ))->toArray();
    }

    public function pathDirectories(string $appDirectory): array
    {
        return [$appDirectory . '/' . self::BIN];
    }

    public function nativeTools(): ?NativeTools
    {
        return app(NativeGoTools::class);
    }

    public function frameworkLabel(string $framework): ?string
    {
        return GoFrameworks::label($framework);
    }

    public function explain(string $phase, string $output, Stack $stack): ?string
    {
        if ($phase === DeploymentPhases::BUILD && preg_match('/^\.?\/?([\w.\/-]+\.go):(\d+):\d+: (.+)$/m', $output, $error) === 1) {
            return "The Go build stopped at {$error[1]} line {$error[2]}: {$error[3]}. Fix it, then deploy again.";
        }

        if ($phase === DeploymentPhases::BUILD && preg_match('/fatal error: ([\w.\/-]+\.h): No such file or directory/', $output, $header) === 1) {
            return "The build needs the C header {$header[1]}, which isn't on the server. Install the library's development package on the server, then deploy again.";
        }

        if ($phase === DeploymentPhases::DEPENDENCIES && preg_match('/go: ([\w.\/-]+)@[\w.+-]+: .*(?:terminal prompts disabled|could not read Username|Repository not found|410 Gone|404 Not Found)/', $output, $module) === 1) {
            return "Go couldn't download {$module[1]}, which looks private. Commit the app's dependencies with go mod vendor, so the deploy needs no access to them, then deploy again.";
        }

        return null;
    }

    private function go(Stack $stack): ?string
    {
        $version = $stack->versions()['go'] ?? null;

        return $version === null ? null : 'GOTOOLCHAIN=local ' . $this->asdf->binaryPath('go', $version);
    }

    private function saved(Application $application): ?GoTooling
    {
        $data = ((array) $application->stack)['language_tooling'] ?? null;

        return GoTooling::fromArray(is_array($data) ? $data : null);
    }
}
