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
