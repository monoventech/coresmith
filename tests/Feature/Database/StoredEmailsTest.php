<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function storedEmailsAccount(string $email, string $createdAt): void
{
    DB::table('users')->insert(['id' => (string) Str::ulid(), 'name' => 'Someone', 'email' => $email, 'password' => 'x', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
}

test('stored emails are lowercased unless the lowercased address already names another account', function () {
    storedEmailsAccount('Grace@Example.com', '2026-01-01 00:00:00');
    storedEmailsAccount('ada@example.com', '2026-01-02 00:00:00');
    storedEmailsAccount('ADA@example.com', '2026-01-03 00:00:00');
    storedEmailsAccount('Bob@Example.com', '2026-01-04 00:00:00');
    storedEmailsAccount('BOB@example.com', '2026-01-05 00:00:00');

    (require database_path('migrations/2026_11_02_110921_lowercase_stored_emails.php'))->up();

    expect(DB::table('users')->orderBy('created_at')->pluck('email')->all())->toBe([
        'grace@example.com',
        'ada@example.com',
        'ADA@example.com',
        'bob@example.com',
        'BOB@example.com',
    ]);
});

test('an address that only folds onto another is still lowercased, and both accounts stay', function () {
    storedEmailsAccount('ẞ@example.com', '2026-01-01 00:00:00');
    storedEmailsAccount('ss@example.com', '2026-01-02 00:00:00');
    storedEmailsAccount(' jose@example.com', '2026-01-03 00:00:00');
    storedEmailsAccount('JOSE@example.com', '2026-01-04 00:00:00');

    (require database_path('migrations/2026_11_02_110921_lowercase_stored_emails.php'))->up();

    expect(DB::table('users')->orderBy('created_at')->pluck('email')->all())->toBe([
        'ß@example.com',
        'ss@example.com',
        'jose@example.com',
        'JOSE@example.com',
    ]);
});

test('stored emails are lowercased only once the column compares exactly', function () {
    $migrations = array_map(basename(...), File::glob(database_path('migrations/*.php')));
    $position = fn(string $suffix): int => (int) array_key_first(array_filter($migrations, fn(string $file): bool => str_ends_with($file, $suffix)));

    expect($position('_compare_emails_exactly.php'))->toBeLessThan($position('_lowercase_stored_emails.php'));
});
