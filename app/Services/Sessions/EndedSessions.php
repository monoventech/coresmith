<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sessions;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\ExistenceAwareInterface;
use SessionHandlerInterface;

final class EndedSessions implements ExistenceAwareInterface, SessionHandlerInterface
{
    private const KEY = 'session-ended:';

    public function __construct(
        private readonly SessionHandlerInterface $handler,
        private readonly Repository $cache,
        private readonly int $minutes,
    ) {}

    public function ended(string $id): bool
    {
        return $id !== '' && $this->cache->has(self::KEY . $id);
    }

    public function keepAlive(string $id): void
    {
        if ($this->ended($id)) {
            return;
        }

        if ($this->handler instanceof CacheBasedSessionHandler) {
            $this->handler->getCache()->touch($id, $this->minutes * 60);

            return;
        }

        $data = $this->handler->read($id);

        if (is_string($data) && $data !== '') {
            $this->handler->write($id, $data);
        }
    }

    public function open(string $path, string $name): bool
    {
        return $this->handler->open($path, $name);
    }

    public function close(): bool
    {
        return $this->handler->close();
    }

    public function read(string $id): string|false
    {
        return $this->ended($id) ? '' : $this->handler->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return $this->ended($id) || $this->handler->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->cache->put(self::KEY . $id, true, now()->addMinutes(max(1, $this->minutes)));

        return $this->handler->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->handler->gc($max_lifetime);
    }

    public function setExists($value)
    {
        if ($this->handler instanceof ExistenceAwareInterface) {
            $this->handler->setExists($value);
        }

        return $this;
    }
}
