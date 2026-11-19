<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('the confirmation email links to an address that carries no account id', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_contains($url, '/email/verify/' . sha1($user->email) . '?') && !str_contains($url, (string) $user->id);
    });
});

test('the link confirms the signed-in account it was sent to', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(URL::temporarySignedRoute('verification.confirm', now()->addHour(), ['hash' => sha1($user->email)]))
        ->assertRedirect(route('dashboard', absolute: false) . '?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('the link refuses another account, a link without its signature and one that expired', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $this->actingAs($other)->get(URL::temporarySignedRoute('verification.confirm', now()->addHour(), ['hash' => sha1($user->email)]))->assertForbidden();
    $this->actingAs($user)->get(route('verification.confirm', ['hash' => sha1($user->email)]))->assertForbidden();
    $this->actingAs($user)->get(URL::temporarySignedRoute('verification.confirm', now()->subMinute(), ['hash' => sha1($user->email)]))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});
