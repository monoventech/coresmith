<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Moves\MoveCodes;
use App\Services\Moves\MoveFile;
use Illuminate\Validation\ValidationException;

function moveFileRefusal(callable $open): string
{
    try {
        $open();
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('Expected the file to be refused.');
}

test('a move file opens with the code it was sealed to', function () {
    config(['coresmith.version' => '0.2.0']);
    $codes = app(MoveCodes::class);
    $code = $codes->create(User::factory()->create());
    $file = app(MoveFile::class)->seal(['team' => ['name' => 'Acme']], $codes->read($codes->text($code)));
    $header = json_decode($file, true);

    expect($header)->toMatchArray(['format' => 1, 'coresmith' => '0.2.0', 'code' => $code->id])
        ->and($file)->not->toContain('Acme')
        ->and(app(MoveFile::class)->open($file, $code->fresh()))->toBe(['team' => ['name' => 'Acme']]);
});

test('a move file sealed to another code is refused', function () {
    $codes = app(MoveCodes::class);
    $mine = $codes->create(User::factory()->create());
    $other = $codes->create(User::factory()->create());
    $file = app(MoveFile::class)->seal(['team' => ['name' => 'Acme']], $codes->read($codes->text($other)));

    expect(moveFileRefusal(fn() => app(MoveFile::class)->open($file, $mine->fresh())))
        ->toBe('This move file was made for a different move code. Paste the code from here into Coresmith Cloud and download the file again.');
});

test('a move file whose sealed part was changed is refused', function () {
    $codes = app(MoveCodes::class);
    $code = $codes->create(User::factory()->create());
    $header = json_decode(app(MoveFile::class)->seal(['team' => ['name' => 'Acme']], $codes->read($codes->text($code))), true);
    $sealed = base64_decode($header['sealed']);
    $sealed[40] = $sealed[40] === 'A' ? 'B' : 'A';
    $header['sealed'] = base64_encode($sealed);

    expect(moveFileRefusal(fn() => app(MoveFile::class)->open((string) json_encode($header), $code->fresh())))
        ->toBe("This move file can't be opened. Download it again from Coresmith Cloud.");
});

test('a move file from a newer coresmith asks for an update', function () {
    $code = app(MoveCodes::class)->create(User::factory()->create());
    $file = (string) json_encode(['format' => 2, 'coresmith' => '0.9.0', 'code' => $code->id, 'sealed' => base64_encode('x')]);

    expect(moveFileRefusal(fn() => app(MoveFile::class)->open($file, $code->fresh())))
        ->toBe('This move file comes from Coresmith 0.9.0, which is newer than this one. Update Coresmith, then upload the file again.');
});

test('a file that is not a move file is refused', function (string $file) {
    $code = app(MoveCodes::class)->create(User::factory()->create());

    expect(moveFileRefusal(fn() => app(MoveFile::class)->open($file, $code->fresh())))
        ->toBe("This isn't a Coresmith move file. Download it again from Coresmith Cloud.");
})->with([
    'empty' => '',
    'text' => 'hello',
    'other json' => '{"name":"Acme"}',
]);

test('a move file cannot be opened once its code is used or expired', function () {
    $codes = app(MoveCodes::class);
    $used = $codes->create(User::factory()->create());
    $usedFile = app(MoveFile::class)->seal(['a' => 1], $codes->read($codes->text($used)));
    $used->forceFill(['imported_at' => now(), 'secret_key' => null])->save();
    $expired = $codes->create(User::factory()->create());
    $expiredFile = app(MoveFile::class)->seal(['a' => 1], $codes->read($codes->text($expired)));

    $this->travel(25)->hours();

    expect(moveFileRefusal(fn() => app(MoveFile::class)->open($usedFile, $used->fresh())))
        ->toBe('This move code has already been used. Create a new one, then download the file again in Coresmith Cloud.')
        ->and(moveFileRefusal(fn() => app(MoveFile::class)->open($expiredFile, $expired->fresh())))
        ->toBe('This move code has expired. Create a new one, then download the file again in Coresmith Cloud.');
});
