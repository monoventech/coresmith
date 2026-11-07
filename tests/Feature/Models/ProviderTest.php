<?php

declare(strict_types=1);

use App\Models\CloudProvider;
use App\Models\SourceProvider;
use Illuminate\Support\Facades\DB;

test('source provider tokens are encrypted at rest', function () {
    $provider = SourceProvider::factory()->create([
        'access_token' => 'ghs_secrettoken',
        'refresh_token' => 'ghr_refreshtoken',
    ]);

    $row = DB::table('source_providers')->where('id', $provider->id)->first();

    expect($row->access_token)->not->toContain('ghs_secrettoken')
        ->and($row->refresh_token)->not->toContain('ghr_refreshtoken')
        ->and($provider->fresh()->access_token)->toBe('ghs_secrettoken')
        ->and($provider->fresh()->refresh_token)->toBe('ghr_refreshtoken');
});

test('source provider scopes round-trip as an array', function () {
    expect(SourceProvider::factory()->create(['scopes' => ['repo', 'read:user']])->fresh()->scopes)
        ->toBe(['repo', 'read:user']);
});

test('a null token stays null rather than encrypting', function () {
    expect(SourceProvider::factory()->create(['refresh_token' => null])->fresh()->refresh_token)->toBeNull();
});

test('cloud provider api tokens are encrypted at rest', function () {
    $provider = CloudProvider::factory()->create(['api_token' => 'hetzner-api-key']);

    expect(DB::table('cloud_providers')->where('id', $provider->id)->value('api_token'))->not->toContain('hetzner-api-key')
        ->and($provider->fresh()->api_token)->toBe('hetzner-api-key');
});

test('providers belong to a team', function () {
    expect(CloudProvider::factory()->create()->team)->not->toBeNull()
        ->and(SourceProvider::factory()->create()->team)->not->toBeNull();
});
