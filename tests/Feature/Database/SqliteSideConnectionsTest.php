<?php

declare(strict_types=1);

test('the queue and cache SQLite files wait for a lock instead of failing at once', function (string $connection) {
    expect(config("database.connections.{$connection}.busy_timeout"))->toBe(5000);
})->with(['sqlite_queue', 'sqlite_cache']);
