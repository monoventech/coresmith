<?php

declare(strict_types=1);

use App\Models\User;

test('a guest opening Coresmith\'s address is sent to sign in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('someone signed in opening Coresmith\'s address goes to their dashboard', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard'));
});
