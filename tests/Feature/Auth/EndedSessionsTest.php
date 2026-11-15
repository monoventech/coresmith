<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Sessions\EndedSessions;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

function endedSessionsSignedIn(User $user): string
{
    $store = app('session')->driver();
    $store->setId(null);
    $store->start();
    $store->put(Auth::guard('web')->getName(), $user->getKey());
    $store->save();

    return $store->getId();
}

test('a request still in flight when someone signs out cannot write the old session back', function () {
    $store = app('session')->driver();
    $old = endedSessionsSignedIn(User::factory()->create());

    $late = new Store($store->getName(), $store->getHandler(), $old);
    $late->start();

    $store->setId($old);
    $store->start();
    $store->invalidate();
    $store->save();

    $late->put('touched', true);
    $late->save();

    expect($store->getHandler())->toBeInstanceOf(EndedSessions::class)
        ->and($store->getHandler()->read($old))->toBe('')
        ->and($store->getId())->not->toBe($old);
});

test('signing out ends the session for good, so its cookie never signs anyone in again', function () {
    $old = endedSessionsSignedIn(User::factory()->create());

    $this->withCookie(config('session.cookie'), $old)->post(route('logout'))->assertRedirect();

    expect(app('session')->driver()->getHandler()->ended($old))->toBeTrue();

    $this->withCookie(config('session.cookie'), $old)->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a browser that still sends an ended session is given a fresh one that keeps its data', function () {
    $handler = app('session')->driver()->getHandler();
    $old = Str::random(40);
    $handler->destroy($old);

    $this->withCookie(config('session.cookie'), $old)->get(route('login'))->assertOk();

    $fresh = app('session')->driver()->getId();

    expect($fresh)->not->toBe($old)
        ->and($handler->read($fresh))->not->toBe('');
});
