<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\NativeDnsResolver;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamContext;
use App\Services\VaultEncryptor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            VaultEncryptor::class,
            fn(): VaultEncryptor => new VaultEncryptor(config('coresmith.vault_key')),
        );

        $this->app->singleton(SSHConnectionManager::class);

        $this->app->scoped(TeamContext::class);

        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        if (!app()->hasDebugModeEnabled()) {
            ini_set('zend.exception_ignore_args', '1');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn(): ?Password => app()->isProduction()
                ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
                : null,
        );
    }
}
