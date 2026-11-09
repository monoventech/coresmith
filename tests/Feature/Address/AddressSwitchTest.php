<?php

declare(strict_types=1);

use App\Services\Address\AddressChange;
use App\Services\Address\AddressRequest;
use App\Services\Address\AddressSwitch;
use App\Services\Address\CaddyAdmin;
use App\Services\Address\CertificateProbe;
use App\Services\Address\CertificateSource;
use App\Services\Address\EnvironmentFile;
use App\Services\Address\SiteFile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

final class ChangeAddressCaddy implements CaddyAdmin
{
    /** @var list<string> */
    public array $loaded = [];

    /** @var list<?string> */
    public array $states = [];

    public bool $managed = false;

    public function __construct(private readonly string $site, private int $refusals = 0) {}

    public function managed(): bool
    {
        return $this->managed;
    }

    public function reload(): void
    {
        if ($this->refusals > 0) {
            $this->refusals--;

            throw new RuntimeException('Caddy refused the new site: ambiguous site definition: coresmith.example.com');
        }

        $this->loaded[] = (string) file_get_contents($this->site);
        $this->states[] = AddressChange::current()['state'] ?? null;
    }
}

final class ChangeAddressProbe implements CertificateProbe
{
    /** @var list<CertificateSource> */
    public array $asked = [];

    public function __construct(private int $before = 0) {}

    public function serves(string $host, CertificateSource $source): bool
    {
        $this->asked[] = $source;

        return $this->before-- <= 0;
    }
}

function changeAddressMachine(int $refusals = 0, int $certificateAfter = 0): array
{
    $directory = test()->addressDirectory;
    file_put_contents("{$directory}/panel.caddy", "192.168.0.248 {\n}\n");
    file_put_contents("{$directory}/.env", "APP_URL=https://192.168.0.248\nVITE_REVERB_HOST=192.168.0.248\n");
    file_put_contents("{$directory}/tailscaled", "PORT=\"41641\"\nTS_PERMIT_CERT_UID=caddy\n");

    config([
        'app.url' => 'https://192.168.0.248',
        'coresmith.address.site' => "{$directory}/panel.caddy",
        'coresmith.address.apps_config' => "{$directory}/caddy-config.json",
        'coresmith.address.tailscaled_defaults' => "{$directory}/tailscaled",
        'coresmith.address.state_path' => $directory,
    ]);

    $caddy = new ChangeAddressCaddy("{$directory}/panel.caddy", $refusals);
    $probe = new ChangeAddressProbe($certificateAfter);
    app()->instance(CaddyAdmin::class, $caddy);
    app()->instance(CertificateProbe::class, $probe);
    app()->instance(EnvironmentFile::class, new EnvironmentFile("{$directory}/.env"));

    return [$directory, $caddy, $probe];
}

function changeAddressTo(string $host): void
{
    AddressChange::begin('192.168.0.248', $host);
    app(AddressSwitch::class)->run($host);
}

beforeEach(function () {
    $this->addressDirectory = sys_get_temp_dir() . '/coresmith-address-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->addressDirectory);
    Process::preventStrayProcesses();
    Sleep::fake();

    $this->artisanCalls = [];
    $this->artisanAnswers = ['horizon:terminate' => 0, 'reverb:restart' => 0, 'config:cache' => 0];
    $kernel = app(Kernel::class);
    $proxy = Mockery::mock($kernel);
    $proxy->shouldReceive('call')->andReturnUsing(function (string $command, mixed ...$rest) use ($kernel) {
        $this->artisanCalls[] = $command;

        if (!array_key_exists($command, $this->artisanAnswers)) {
            return $kernel->call($command, ...$rest);
        }

        $answer = $this->artisanAnswers[$command];

        return $answer instanceof Throwable ? throw $answer : $answer;
    });
    Artisan::swap($proxy);
});

afterEach(fn() => File::deleteDirectory($this->addressDirectory));

test('the new address goes live once its certificate is served, and the old one then sends people on', function () {
    [$directory, $caddy, $probe] = changeAddressMachine(certificateAfter: 2);

    changeAddressTo('coresmith.example.com');

    expect($caddy->loaded)->toHaveCount(2)
        ->and($caddy->loaded[0])->toStartWith("192.168.0.248, coresmith.example.com {\n")
        ->and($caddy->loaded[1])->toStartWith("coresmith.example.com {\n")
        ->and($caddy->loaded[1])->toEndWith("\n192.168.0.248 {\n    redir https://coresmith.example.com{uri} permanent\n}\n")
        ->and($probe->asked)->toBe([CertificateSource::Public, CertificateSource::Public, CertificateSource::Public])
        ->and(file_get_contents("{$directory}/.env"))->toBe("APP_URL=https://coresmith.example.com\nVITE_REVERB_HOST=coresmith.example.com\n")
        ->and(AddressChange::current())->toMatchArray(['state' => 'done', 'from' => '192.168.0.248', 'to' => 'coresmith.example.com', 'error' => null])
        ->and(Activity::query()->where('description', 'address_changed')->sole()->properties->all())->toBeInAnyKeyOrder(['from' => '192.168.0.248', 'to' => 'coresmith.example.com']);
});

test('the change counts as done while the old address still serves coresmith, so an open page can see it', function () {
    [, $caddy] = changeAddressMachine();

    changeAddressTo('coresmith.example.com');

    expect($caddy->states)->toBe(['running', 'done']);
});

test('an ipv6 address is written into the environment the way urls need it', function () {
    [$directory] = changeAddressMachine();

    changeAddressTo('fd00::248');

    expect(file_get_contents("{$directory}/.env"))->toBe("APP_URL=https://[fd00::248]\nVITE_REVERB_HOST=[fd00::248]\n");
});

test('the background services restart so they pick the new address up', function () {
    changeAddressMachine();

    changeAddressTo('coresmith.example.com');

    expect($this->artisanCalls)->toBe(['horizon:terminate', 'reverb:restart']);
});

test('a certificate that never comes leaves everything on the old address and says why', function () {
    [$directory, $caddy] = changeAddressMachine(certificateAfter: PHP_INT_MAX);

    changeAddressTo('coresmith.example.com');

    expect($caddy->loaded)->toHaveCount(2)
        ->and($caddy->loaded[1])->toBe(app(SiteFile::class)->render(['192.168.0.248']))
        ->and(file_get_contents("{$directory}/.env"))->toBe("APP_URL=https://192.168.0.248\nVITE_REVERB_HOST=192.168.0.248\n")
        ->and(AddressChange::current())->toMatchArray([
            'state' => 'failed',
            'error' => 'No certificate for coresmith.example.com after 3 minutes. Check that it points at this server and that ports 80 and 443 reach this server from the internet, then try again.',
        ]);

    Sleep::assertSleptTimes(36);
});

test('an internal name is served with caddy\'s own certificate, so only its name is checked', function () {
    [, , $probe] = changeAddressMachine();

    changeAddressTo('coresmith.internal');

    expect($probe->asked)->toBe([CertificateSource::Internal])
        ->and(AddressChange::current()['state'])->toBe('done');
});

test('caddy refusing the new site leaves the old one in place', function () {
    [$directory, $caddy] = changeAddressMachine(refusals: 1);

    changeAddressTo('coresmith.example.com');

    expect($caddy->loaded)->toHaveCount(1)
        ->and(file_get_contents("{$directory}/panel.caddy"))->toBe(app(SiteFile::class)->render(['192.168.0.248']))
        ->and(AddressChange::current())->toMatchArray(['state' => 'failed', 'error' => 'Caddy refused the new site: ambiguous site definition: coresmith.example.com']);
});

test('a server that also serves apps through caddy is left alone', function () {
    [$directory, $caddy] = changeAddressMachine();
    file_put_contents("{$directory}/caddy-config.json", '{"apps":{"http":{}}}');

    changeAddressTo('coresmith.example.com');

    expect($caddy->loaded)->toBe([])
        ->and(file_get_contents("{$directory}/panel.caddy"))->toBe("192.168.0.248 {\n}\n")
        ->and(AddressChange::current())->toMatchArray([
            'state' => 'failed',
            'error' => 'This server also serves apps through Caddy, so Coresmith leaves its Caddy setup alone. Run the installer again with --domain instead.',
        ]);
});

test("on Coresmith's own machine, which also serves apps, the address still changes, Coresmith's routes only", function () {
    [$directory, $caddy] = changeAddressMachine();
    $caddy->managed = true;
    file_put_contents("{$directory}/caddy-config.json", '{"apps":{"http":{}}}');

    changeAddressTo('coresmith.example.com');

    expect(AddressChange::current()['state'])->toBe('done')
        ->and($caddy->loaded)->not->toBe([]);
});

test('a tailscale name is checked with tailscale before caddy is touched', function (array $status, string $defaults, string $refusal) {
    [$directory, $caddy] = changeAddressMachine();
    file_put_contents("{$directory}/tailscaled", $defaults);
    Process::fake(['tailscale status --json' => Process::result(json_encode($status))]);

    changeAddressTo('beelink.tail1234.ts.net');

    expect($caddy->loaded)->toBe([])
        ->and(AddressChange::current())->toMatchArray(['state' => 'failed', 'error' => $refusal]);
})->with([
    'not signed in' => [
        ['BackendState' => 'NeedsLogin', 'Self' => ['DNSName' => ''], 'CertDomains' => null],
        "TS_PERMIT_CERT_UID=caddy\n",
        "Tailscale on this server isn't signed in. Run tailscale up on it, then try again.",
    ],
    'another name' => [
        ['BackendState' => 'Running', 'Self' => ['DNSName' => 'forge.tail1234.ts.net.'], 'CertDomains' => ['forge.tail1234.ts.net']],
        "TS_PERMIT_CERT_UID=caddy\n",
        "This server's Tailscale name is forge.tail1234.ts.net. Use that name instead.",
    ],
    'certificates off' => [
        ['BackendState' => 'Running', 'Self' => ['DNSName' => 'beelink.tail1234.ts.net.'], 'CertDomains' => null],
        "TS_PERMIT_CERT_UID=caddy\n",
        'HTTPS certificates are off for your tailnet. Turn them on in the Tailscale admin console under DNS, then try again.',
    ],
    'caddy not allowed to fetch them' => [
        ['BackendState' => 'Running', 'Self' => ['DNSName' => 'beelink.tail1234.ts.net.'], 'CertDomains' => ['beelink.tail1234.ts.net']],
        "PORT=\"41641\"\n",
        "Caddy isn't allowed to fetch Tailscale certificates. Add TS_PERMIT_CERT_UID=caddy to /etc/default/tailscaled and restart tailscaled, then try again.",
    ],
]);

test('a server without tailscale says to install it', function () {
    changeAddressMachine();
    Process::fake(['tailscale status --json' => Process::result('', 'tailscale: command not found', 127)]);

    changeAddressTo('beelink.tail1234.ts.net');

    expect(AddressChange::current()['error'])->toBe("Tailscale isn't running on this server. Install it and run tailscale up, then try again.");
});

test('a ready tailscale name goes live with the certificate tailscale issues', function () {
    [, $caddy, $probe] = changeAddressMachine();
    Process::fake(['tailscale status --json' => Process::result(json_encode(['BackendState' => 'Running', 'Self' => ['DNSName' => 'beelink.tail1234.ts.net.'], 'CertDomains' => ['beelink.tail1234.ts.net']]))]);

    changeAddressTo('beelink.tail1234.ts.net');

    expect($probe->asked)->toBe([CertificateSource::Tailscale])
        ->and($caddy->loaded[1])->toStartWith("beelink.tail1234.ts.net {\n")
        ->and(AddressChange::current()['state'])->toBe('done');
});

test('an environment file that cannot be written leaves the old address in place', function () {
    [$directory, $caddy] = changeAddressMachine();
    File::ensureDirectoryExists("{$directory}/shared");
    file_put_contents("{$directory}/shared/.env", "APP_URL=https://192.168.0.248\n");
    app()->instance(EnvironmentFile::class, new EnvironmentFile("{$directory}/shared/.env"));
    chmod("{$directory}/shared", 0555);

    try {
        changeAddressTo('coresmith.example.com');
    } finally {
        chmod("{$directory}/shared", 0755);
    }

    expect($caddy->loaded)->toHaveCount(2)
        ->and($caddy->loaded[1])->toBe(app(SiteFile::class)->render(['192.168.0.248']))
        ->and(file_get_contents("{$directory}/shared/.env"))->toBe("APP_URL=https://192.168.0.248\n")
        ->and(AddressChange::current()['state'])->toBe('failed')
        ->and(AddressChange::current()['error'])->toStartWith("Coresmith couldn't update " . realpath("{$directory}/shared") . '/.env: ');
});

test('a restart that fails after the move is reported, and the move still counts', function () {
    changeAddressMachine();
    $this->artisanAnswers['horizon:terminate'] = new RuntimeException('Redis went away.');

    changeAddressTo('coresmith.example.com');

    expect(AddressChange::current()['state'])->toBe('done')
        ->and($this->artisanCalls)->toContain('reverb:restart');
});

test('an install that runs from cached configuration re-caches it with the new address', function () {
    changeAddressMachine();
    app()->instance('config_loaded_from_cache', true);

    changeAddressTo('coresmith.example.com');

    expect($this->artisanCalls)->toBe(['config:cache', 'horizon:terminate', 'reverb:restart'])
        ->and(AddressChange::current()['state'])->toBe('done');
});

test('configuration that cannot be cached puts the old address back', function () {
    [$directory, $caddy] = changeAddressMachine();
    app()->instance('config_loaded_from_cache', true);
    $this->artisanAnswers['config:cache'] = 1;
    config(['coresmith.live.socket.host' => '192.168.0.248']);

    changeAddressTo('coresmith.example.com');

    expect(file_get_contents("{$directory}/.env"))->toBe("APP_URL=https://192.168.0.248\nVITE_REVERB_HOST=192.168.0.248\n")
        ->and(end($caddy->loaded))->toBe(app(SiteFile::class)->render(['192.168.0.248']))
        ->and(AddressChange::current())->toMatchArray(['state' => 'failed', 'error' => "Coresmith couldn't load its new configuration, so it stays on 192.168.0.248."]);
});

test('a crash once the move is done leaves it reported as done', function () {
    changeAddressMachine();
    app()->instance(CaddyAdmin::class, new class implements CaddyAdmin
    {
        private int $loads = 0;

        public function reload(): void
        {
            if (++$this->loads === 2) {
                throw new LogicException('A bug after the move.');
            }
        }

        public function managed(): bool
        {
            return false;
        }
    });
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressRequest::submit('coresmith.example.com');

    $this->artisan('coresmith:address')->assertSuccessful();

    expect(AddressChange::current()['state'])->toBe('done');
});

test('the command moves coresmith to the address asked for, once', function () {
    [$directory, $caddy] = changeAddressMachine();
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressRequest::submit('coresmith.example.com');
    rename(AddressRequest::file(), AddressRequest::takenFile());

    $this->artisan('coresmith:address')->expectsOutput('Coresmith now answers on coresmith.example.com.')->assertSuccessful();

    expect(AddressChange::current()['state'])->toBe('done')
        ->and(file_exists(AddressRequest::takenFile()))->toBeFalse()
        ->and($caddy->loaded)->toHaveCount(2);

    $this->artisan('coresmith:address')->expectsOutput('No address change was requested.')->assertSuccessful();

    expect($caddy->loaded)->toHaveCount(2);
});

test('the command says why a move failed and exits with an error', function () {
    changeAddressMachine(certificateAfter: PHP_INT_MAX);
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressRequest::submit('coresmith.example.com');

    $this->artisan('coresmith:address')
        ->expectsOutput('No certificate for coresmith.example.com after 3 minutes. Check that it points at this server and that ports 80 and 443 reach this server from the internet, then try again.')
        ->assertFailed();
});

test('a move that stops part way says so, so the page stops waiting', function () {
    changeAddressMachine();
    app()->instance(CaddyAdmin::class, new class implements CaddyAdmin
    {
        public function reload(): void
        {
            throw new LogicException('A bug, not a refusal.');
        }

        public function managed(): bool
        {
            return false;
        }
    });
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressRequest::submit('coresmith.example.com');

    $this->artisan('coresmith:address')->assertFailed();

    expect(AddressChange::current())->toMatchArray([
        'state' => 'failed',
        'error' => "Changing the address to coresmith.example.com stopped part way. Try again, and check Caddy's log with journalctl -u caddy if it keeps failing.",
    ]);
});
