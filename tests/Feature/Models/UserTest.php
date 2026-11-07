<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('instance owners are users with the owner role on any team', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $nominal = User::factory()->create();
    $member = User::factory()->create();

    $first = Team::factory()->create();
    $second = Team::factory()->create(['owner_id' => $nominal->id]);
    $first->members()->attach([$owner->id => ['role' => TeamRole::Owner->value], $admin->id => ['role' => TeamRole::Admin->value]]);
    $second->members()->attach([$owner->id => ['role' => TeamRole::Member->value], $nominal->id => ['role' => TeamRole::Admin->value], $member->id => ['role' => TeamRole::Member->value]]);

    expect($owner->managesInstance())->toBeTrue()
        ->and($admin->managesInstance())->toBeFalse()
        ->and($nominal->managesInstance())->toBeFalse()
        ->and($member->managesInstance())->toBeFalse()
        ->and(User::query()->instanceOwners()->pluck('id')->all())->toBe([$owner->id]);
});

test('an email is stored lowercased and trimmed, whoever writes it', function () {
    $user = User::factory()->create(['email' => '  Ada@Example.COM ']);

    expect($user->fresh()->email)->toBe('ada@example.com');

    $user->forceFill(['email' => 'Grace.Hopper@Example.com'])->save();

    expect($user->fresh()->email)->toBe('grace.hopper@example.com');
});

test('an email names exactly the account stored under it', function () {
    $jose = User::factory()->create(['email' => 'jose@acmé.com']);
    DB::table('users')->insert(['id' => (string) Str::ulid(), 'name' => 'Left As It Was', 'email' => 'ADA@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $ada = User::factory()->create(['email' => 'ada@example.com']);

    expect(User::findByEmail('jose@acme.com'))->toBeNull()
        ->and(User::findByEmail(' JOSE@ACMÉ.COM ')?->is($jose))->toBeTrue()
        ->and(User::findByEmail('Ada@Example.com')?->is($ada))->toBeTrue();
});
