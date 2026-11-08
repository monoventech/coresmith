<?php

declare(strict_types=1);

use App\Models\MoveCode;
use App\Models\User;
use App\Services\Moves\MoveCodes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

function moveCodesRefusal(callable $read): string
{
    try {
        $read();
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('Expected the code to be refused.');
}

test('a move code names this panel, carries its public key and works for a day', function () {
    config(['app.url' => 'https://panel.example.com']);
    $this->travelTo(Carbon::parse('2026-11-08 12:00:00'));
    $codes = app(MoveCodes::class);

    $code = $codes->create(User::factory()->create());
    $target = $codes->read($codes->text($code));

    expect($codes->text($code))->toStartWith('csmove1_')
        ->and($target->id)->toBe($code->id)
        ->and($target->panel)->toBe('https://panel.example.com')
        ->and($target->until->toDateTimeString())->toBe('2026-11-09 12:00:00')
        ->and(strlen($target->publicKey))->toBe(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)
        ->and($code->fresh()->usable())->toBeTrue();
});

test('the private half of a move code is sealed at rest', function () {
    $code = app(MoveCodes::class)->create(User::factory()->create());
    $raw = (string) $code->fresh()->getRawOriginal('secret_key');

    expect($raw)->not->toBe($code->fresh()->secret_key)
        ->and(strlen(base64_decode((string) $code->fresh()->secret_key, true)))->toBe(SODIUM_CRYPTO_BOX_KEYPAIRBYTES)
        ->and($code->fresh()->toArray())->not->toHaveKey('secret_key');
});

test('text that is not a move code is refused', function (string $text) {
    expect(moveCodesRefusal(fn() => app(MoveCodes::class)->read($text)))
        ->toBe("That isn't a move code. Copy it again from Settings, General on your panel.");
})->with([
    'empty' => '',
    'another prefix' => 'csmove9_eyJ2IjoxfQ',
    'not base64 json' => 'csmove1_%%%',
    'no public key' => 'csmove1_' . rtrim(strtr(base64_encode('{"v":1,"id":"01J","until":4102444800,"panel":"https://x.test"}'), '+/', '-_'), '='),
    'a short key' => 'csmove1_' . rtrim(strtr(base64_encode('{"v":1,"id":"01J","key":"AAAA","until":4102444800,"panel":"https://x.test"}'), '+/', '-_'), '='),
]);

test('a move code past its time is refused', function () {
    $codes = app(MoveCodes::class);
    $text = $codes->text($codes->create(User::factory()->create()));

    $this->travel(25)->hours();

    expect(moveCodesRefusal(fn() => $codes->read($text)))
        ->toBe('This move code has expired. Create a new one in Settings, General on your panel.');
});

test('a move code stops being usable once it is imported or expires', function () {
    $codes = app(MoveCodes::class);
    $imported = $codes->create(User::factory()->create());
    $imported->forceFill(['imported_at' => now(), 'secret_key' => null])->save();
    $expired = $codes->create(User::factory()->create());

    $this->travel(25)->hours();

    expect($imported->fresh()->usable())->toBeFalse()
        ->and($expired->fresh()->usable())->toBeFalse();
});

test('codes that expired unused are pruned and used ones are kept', function () {
    $codes = app(MoveCodes::class);
    $unused = $codes->create(User::factory()->create());
    $used = $codes->create(User::factory()->create());
    $used->forceFill(['imported_at' => now(), 'secret_key' => null])->save();

    $this->travel(25)->hours();
    $this->artisan('model:prune', ['--model' => [MoveCode::class]])->assertSuccessful();

    expect(MoveCode::query()->find($unused->id))->toBeNull()
        ->and(MoveCode::query()->find($used->id))->not->toBeNull();
});
