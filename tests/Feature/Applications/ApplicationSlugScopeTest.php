<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Server;
use App\Services\SlugGenerator;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function slugScopeMigration(): object
{
    return require base_path('database/migrations/2026_11_23_100000_scope_application_slugs_to_servers.php');
}

/**
 * @return list<list<string>>
 */
function slugScopeUniqueIndexes(): array
{
    return collect(Schema::getIndexes('applications'))
        ->filter(fn(array $index): bool => $index['unique'] && !$index['primary'])
        ->map(fn(array $index): array => $index['columns'])
        ->values()
        ->all();
}

afterEach(function () {
    if (DB::connection() instanceof MySqlConnection) {
        $this->artisan('migrate:fresh');
    }
});

test('two servers can each have an app called api', function () {
    $first = Application::factory()->create(['slug' => 'api']);
    $second = Application::factory()->create(['slug' => 'api']);

    expect($first->server_id)->not->toBe($second->server_id)
        ->and(Application::query()->where('slug', 'api')->count())->toBe(2);
});

test("one server can't have two apps called api", function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['slug' => 'api']);

    expect(fn() => Application::factory()->for($server)->create(['slug' => 'api']))->toThrow(UniqueConstraintViolationException::class);
});

test('the slug generator only avoids names on the same server', function () {
    $taken = Server::factory()->create();
    $free = Server::factory()->create();
    Application::factory()->for($taken)->create(['slug' => 'api']);
    $generator = app(SlugGenerator::class);

    expect($generator->fromName('api', $free))->toBe('api')
        ->and($generator->fromName('api', $taken))->toMatch('/^api-[a-z0-9]{3}$/');
});

test('a removed app still holds its name on its server', function () {
    $server = Server::factory()->create();
    Application::factory()->for($server)->create(['slug' => 'api'])->delete();

    expect(app(SlugGenerator::class)->fromName('api', $server))->toMatch('/^api-[a-z0-9]{3}$/');
});

test('the migration reverses to one name per install and runs again', function () {
    slugScopeMigration()->down();

    expect(slugScopeUniqueIndexes())->toContain(['slug'])->not->toContain(['slug', 'server_id']);

    slugScopeMigration()->up();

    expect(slugScopeUniqueIndexes())->toContain(['slug', 'server_id'])->not->toContain(['slug']);
});
