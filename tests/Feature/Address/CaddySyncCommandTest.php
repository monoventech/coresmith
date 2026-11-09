<?php

declare(strict_types=1);

use App\Services\Address\CaddyAdmin;

function caddySyncAdmin(bool $managed, ?Throwable $refusal = null): object
{
    $caddy = new class($managed, $refusal) implements CaddyAdmin
    {
        public int $reloads = 0;

        public function __construct(private readonly bool $isManaged, private readonly ?Throwable $refusal) {}

        public function reload(): void
        {
            $this->reloads++;

            if ($this->refusal !== null) {
                throw $this->refusal;
            }
        }

        public function managed(): bool
        {
            return $this->isManaged;
        }
    };

    app()->instance(CaddyAdmin::class, $caddy);

    return $caddy;
}

test("an installer run on Coresmith's own machine brings Coresmith's routes in Caddy up to date", function () {
    $caddy = caddySyncAdmin(true);

    $this->artisan('coresmith:caddy-sync')->expectsOutput("Coresmith's routes in Caddy are up to date.")->assertSuccessful();

    expect($caddy->reloads)->toBe(1);
});

test("where Caddy serves Coresmith from its own Caddyfile, there's nothing to bring up to date", function () {
    $caddy = caddySyncAdmin(false);

    $this->artisan('coresmith:caddy-sync')->expectsOutput("Caddy serves Coresmith from its own Caddyfile here, so there's nothing to update.")->assertSuccessful();

    expect($caddy->reloads)->toBe(0);
});

test("a sync Caddy refuses fails the installer's step with Caddy's reason", function () {
    caddySyncAdmin(true, new RuntimeException('Caddy refused the new site: unrecognized directive: foo'));

    $this->artisan('coresmith:caddy-sync')->expectsOutput('Caddy refused the new site: unrecognized directive: foo')->assertFailed();
});
