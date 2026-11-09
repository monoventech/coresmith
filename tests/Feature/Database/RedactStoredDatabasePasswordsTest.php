<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Database\MysqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\DB;

function redactStoredPasswordsCommand(Server $server, string $command, string $description = SSHConnection::COMMAND_ACTIVITY): int
{
    return DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => $description,
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->getKey(),
        'properties' => json_encode(['command' => $command, 'exit_code' => 0, 'output_length' => 0]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function redactStoredPasswordsRead(int $id): array
{
    return json_decode((string) DB::table('activity_log')->where('id', $id)->value('properties'), true);
}

test('database passwords already in the command log are redacted, and the rest of each record stays', function () {
    $server = Server::factory()->create();
    $mysql = new MysqlDriver;
    $postgres = new PostgresDriver;

    $created = redactStoredPasswordsCommand($server, $mysql->command($mysql->createUser('shop_app', 'Secr3tPassw0rdXYZ')));
    $role = redactStoredPasswordsCommand($server, $postgres->command($postgres->createUser('blog_app', 'An0therS3cretValue')));
    $plain = redactStoredPasswordsCommand($server, 'systemctl is-active --quiet caddy');

    (require database_path('migrations/2026_11_09_120000_redact_database_passwords_in_command_activity.php'))->up();

    expect(redactStoredPasswordsRead($created))->toBe([
        'command' => "mysql --protocol=socket -e 'CREATE USER IF NOT EXISTS '\\''shop_app'\\''@'\\''localhost'\\'' IDENTIFIED BY '\\''[REDACTED]'\\'';'",
        'exit_code' => 0,
        'output_length' => 0,
    ])
        ->and(redactStoredPasswordsRead($role)['command'])->not->toContain('An0therS3cretValue')->toContain("PASSWORD '\\''[REDACTED]'\\''")
        ->and(redactStoredPasswordsRead($plain)['command'])->toBe('systemctl is-active --quiet caddy');
});

test('only command records are rewritten', function () {
    $server = Server::factory()->create();
    $note = redactStoredPasswordsCommand($server, "PASSWORD 'kept-as-written-here'", 'note');

    (require database_path('migrations/2026_11_09_120000_redact_database_passwords_in_command_activity.php'))->up();

    expect(redactStoredPasswordsRead($note)['command'])->toBe("PASSWORD 'kept-as-written-here'");
});
