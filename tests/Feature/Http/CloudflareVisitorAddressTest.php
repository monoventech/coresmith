<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(fn() => Route::get('cloudflare-visitor-probe', fn() => response(request()->ip())));

test("behind Cloudflare's proxy, Coresmith sees the visitor's address", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.9'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertOk()
        ->assertContent('198.51.100.23');
});

test("a CF-Connecting-IP header is ignored when the connection doesn't come from Cloudflare", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->withHeader('CF-Connecting-IP', '198.51.100.23')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('203.0.113.50');
});

test("a header that isn't an address leaves Cloudflare's address in place", function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.9'])
        ->withHeader('CF-Connecting-IP', 'nonsense')
        ->get('/cloudflare-visitor-probe')
        ->assertContent('172.70.1.9');
});
