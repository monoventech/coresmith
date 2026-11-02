<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function migrateAllFresh(string $name): Process
{
    $process = new Process(
        [PHP_BINARY, 'artisan', 'migrate:all', '--fresh'],
        base_path(),
        [
            'APP_NAME' => $name,
            'DB_CONNECTION' => 'sqlite',
            'DB_URL' => '',
            'DB_DATABASE' => database_path("{$name}.sqlite"),
            'DB_QUEUE_DATABASE' => database_path("{$name}_queue.sqlite"),
            'DB_CACHE_DATABASE' => database_path("{$name}_cache.sqlite"),
        ],
        null,
        120,
    );

    $process->run();

    return $process;
}

test('migrate:all --fresh starts over on a database that is already migrated, as a new checkout would', function () {
    $name = 'migrate_all_test_' . getmypid();
    $open = fn(string $file = ''): PDO => new PDO('sqlite:' . database_path("{$name}{$file}.sqlite"));

    try {
        $first = migrateAllFresh($name);

        expect($first->getExitCode())->toBe(0, $first->getOutput() . $first->getErrorOutput());

        $open()->exec("insert into migrations (migration, batch) values ('left_behind', 9)");

        $second = migrateAllFresh($name);

        expect($second->getExitCode())->toBe(0, $second->getOutput() . $second->getErrorOutput())
            ->and($open()->query("select count(*) from migrations where migration = 'left_behind'")->fetchColumn())->toBe(0)
            ->and($open()->query('select count(*) from migrations')->fetchColumn())->toBeGreaterThan(0)
            ->and($open('_queue')->query("select count(*) from sqlite_master where name = 'jobs'")->fetchColumn())->toBe(1)
            ->and($open('_queue')->query('pragma journal_mode')->fetchColumn())->toBe('wal')
            ->and($open('_cache')->query("select count(*) from sqlite_master where name = 'cache'")->fetchColumn())->toBe(1)
            ->and($open('_cache')->query('pragma journal_mode')->fetchColumn())->toBe('wal');
    } finally {
        File::delete(File::glob(database_path("{$name}*")));
    }
});
