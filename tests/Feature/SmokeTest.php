<?php

declare(strict_types=1);

test('app name is Coresmith', function () {
    expect(config('app.name'))->toBe('Coresmith');
});

test('welcome page renders Coresmith branding', function () {
    $response = $this->get('/');
    $response->assertStatus(200);
    expect($response->getContent())->toContain('Coresmith');
});

test('telescope routes are removed', function () {
    $response = $this->get('/telescope');
    $response->assertStatus(404);
});

test('coresmith config has billing_enabled false by default', function () {
    expect(config('coresmith.billing_enabled'))->toBeFalse();
});

test('coresmith config exposes vault_key entry', function () {
    expect(config('coresmith'))->toHaveKey('vault_key');
});

test('inertia shares appName, billingEnabled, vaultKeyConfigured', function () {
    $response = $this->get('/');
    $response->assertInertia(
        fn($page) => $page
            ->where('appName', 'Coresmith')
            ->where('billingEnabled', false)
            ->where('vaultKeyConfigured', true)
    );
});

test('vaultKeyConfigured is false when no vault key is set', function () {
    config(['coresmith.vault_key' => null]);

    $this->get('/')->assertInertia(fn($page) => $page->where('vaultKeyConfigured', false));
});
