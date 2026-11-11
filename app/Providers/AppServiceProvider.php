<?php

namespace App\Providers;

use App\Enums\Edition;
use App\Models\ApiToken;
use App\Models\Server;
use App\Services\Address\EnvironmentFile;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\HttpCloudApiFactory;
use App\Services\Database\CoresmithDatabase;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\NativeDnsResolver;
use App\Services\Install\NativeProcessHandOff;
use App\Services\Install\ProcessHandOff;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use App\Services\Teams\TeamContext;
use App\Services\Updates\UpdatePaths;
use App\Services\VaultEncryptor;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->scoped(TeamAccess::class);

        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(EnvironmentFile::class, fn(): EnvironmentFile => new EnvironmentFile($this->app->environmentFilePath()));

        $this->app->bind(UpdatePaths::class, fn(): UpdatePaths => UpdatePaths::fromConfig());

        $this->app->bind(CloudApiFactory::class, HttpCloudApiFactory::class);

        $this->app->bind(ProcessHandOff::class, NativeProcessHandOff::class);

        $this->app->bind(CoresmithDatabase::class, fn(): CoresmithDatabase => CoresmithDatabase::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Edition::current();

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        $this->configureDefaults();

        RateLimiter::for('server-password', fn(Request $request): Limit|array => $this->serverPasswordLimits($request));
    }

    /**
     * @return Limit|list<Limit>
     */
    private function serverPasswordLimits(Request $request): Limit|array
    {
        if (!$request->filled('password')) {
            return Limit::none();
        }

        $server = $request->route('server');
        $limits = [Limit::perMinutes(10, 30)->by('user:' . $request->user()?->getAuthIdentifier())];

        if ($server !== null) {
            $limits[] = Limit::perMinutes(10, 4)->by('server:' . ($server instanceof Server ? $server->getKey() : $server));
        }

        return $limits;
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
