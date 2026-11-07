<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Exceptions\InvalidRouteConfigException;
use App\Models\Application;
use App\Services\Server\PortAllocator;

final class RouteConfigValidator
{
    public const PORT_BLOCK = 10;

    private const LOOPBACK = ['127.0.0.1', 'localhost', '[::1]'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function validate(array $config, Application $application): void
    {
        $this->validateDomains($config, $application);
        $this->validateUpstreams($config, $application);
        $this->validateRoots($config, $application);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateDomains(array $config, Application $application): void
    {
        $allowed = $application->allDomains();

        if ($allowed === []) {
            throw InvalidRouteConfigException::missingDomain();
        }

        foreach ($this->collect($config, 'host') as $hosts) {
            foreach ((array) $hosts as $host) {
                if (!in_array($host, $allowed, true)) {
                    throw InvalidRouteConfigException::domain((string) $host);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateUpstreams(array $config, Application $application): void
    {
        $allowedPorts = range($application->base_port, $application->base_port + self::PORT_BLOCK - 1);

        $expectedSocket = 'unix/' . (new CaddyRouteBuilder)->fpmSocket($application);

        foreach ($this->collect($config, 'upstreams') as $upstreams) {
            foreach ((array) $upstreams as $upstream) {
                $dial = (string) (is_array($upstream) ? ($upstream['dial'] ?? '') : '');

                if (str_starts_with($dial, 'unix/')) {
                    if ($dial !== $expectedSocket) {
                        throw InvalidRouteConfigException::socket($dial);
                    }

                    continue;
                }

                $this->assertLoopbackDial($dial, $allowedPorts);
            }
        }
    }

    /**
     * @param  list<int>  $allowedPorts
     */
    private function assertLoopbackDial(string $dial, array $allowedPorts): void
    {
        $separator = strrpos($dial, ':');

        if ($separator === false) {
            throw InvalidRouteConfigException::upstream($dial);
        }

        $host = substr($dial, 0, $separator);
        $port = (int) substr($dial, $separator + 1);

        if (!in_array($host, self::LOOPBACK, true)) {
            throw InvalidRouteConfigException::upstream($dial);
        }

        if (!PortAllocator::isInAllocatableRange($port)) {
            throw InvalidRouteConfigException::upstream($dial);
        }

        if (!in_array($port, $allowedPorts, true)) {
            throw InvalidRouteConfigException::upstream($dial);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateRoots(array $config, Application $application): void
    {
        $allowed = CaddyRouteBuilder::APPS_ROOT . '/' . $application->slug . '/';

        foreach ($this->collect($config, 'root') as $root) {
            $root = (string) $root;

            if (!str_starts_with($root, $allowed) || str_contains($root, '..')) {
                throw InvalidRouteConfigException::root($root);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return list<mixed>
     */
    private function collect(array $config, string $key): array
    {
        $found = [];

        foreach ($config as $name => $value) {
            if ($name === $key) {
                $found[] = $value;
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->collect($value, $key)];
            }
        }

        return $found;
    }
}
