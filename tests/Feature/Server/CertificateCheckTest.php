<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\UnsafeNameException;
use App\Jobs\CheckCertificates;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Notifications\CertificateExpiringNotification;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\FleetSnapshot;
use App\Services\Server\CertificateCheck;
use App\Services\Server\CertificateProbe;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

function certificateApp(array $attributes = []): Application
{
    $server = Server::factory()->create(['name' => 'web-1', 'ip_address' => '203.0.113.9', 'status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $server->team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);

    return Application::factory()->for($server->team)->for($server)->create([
        'name' => 'Shop',
        'domain' => 'a.example.com',
        'additional_domains' => [],
        ...$attributes,
    ]);
}

function certificateOutput(array $lines): string
{
    return implode("\n", $lines) . "\n";
}

function certificateNotAfter(int $days): string
{
    $moment = CarbonImmutable::now()->addDays($days)->utc();

    return 'notAfter=' . $moment->format('M') . ' ' . str_pad($moment->format('j'), 2, ' ', STR_PAD_LEFT) . ' ' . $moment->format('H:i:s Y') . ' GMT';
}

function certificateRun(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new CheckCertificates($server), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function certificateProbes(FakeSSHTransport $transport): array
{
    return array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, '@@cert')));
}

function certificateDomains(int $count): array
{
    return array_map(fn(int $index): string => "d{$index}.example.com", range(1, $count));
}

test('the probe asks caddy on the server itself, by name, with a timeout', function () {
    $commands = (new CertificateProbe)->commands(['a.example.com', 'b.example.com']);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain("for d in 'a.example.com' 'b.example.com'; do")
        ->and($commands[0])->toContain('timeout 3 openssl s_client')
        ->and($commands[0])->toContain('-servername "$d" -connect 127.0.0.1:443')
        ->and($commands[0])->toContain("printf '@@cert %s ' \"\$d\"")
        ->and($commands[0])->toContain('openssl x509 -noout -enddate 2>/dev/null || echo none');

    $domains = certificateDomains(90);
    $application = certificateApp(['domain' => $domains[0], 'additional_domains' => array_slice($domains, 1)]);
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($application->server, $transport);

    expect(certificateProbes($transport))->toHaveCount(3)
        ->and($transport->budgetFor("'d1.example.com'"))->toBe(150)
        ->and($transport->budgetFor("'d41.example.com'"))->toBe(150)
        ->and($transport->budgetFor("'d81.example.com'"))->toBe(150);
});

test('a name that is not a domain never reaches the shell', function () {
    expect(fn() => (new CertificateProbe)->commands(["a.example.com'; reboot; '"]))->toThrow(UnsafeNameException::class);
});

test('a slow server keeps what was not reached', function () {
    $domains = certificateDomains(50);
    $application = certificateApp(['domain' => $domains[0], 'additional_domains' => array_slice($domains, 1)]);
    $earlier = CarbonImmutable::now()->subHours(7)->toIso8601String();
    Cache::put(CertificateCheck::cacheKey($application), ['domains' => [
        'd45.example.com' => ['expires_at' => CarbonImmutable::now()->addDays(40)->toIso8601String(), 'checked_at' => $earlier],
    ]], now()->addDays(3));
    $transport = (new FakeSSHTransport)
        ->respondTo('@@cert', certificateOutput(['@@cert d1.example.com ' . certificateNotAfter(30)]))
        ->onCommand("'d1.example.com'", fn() => $this->travel(451)->seconds());

    certificateRun($application->server, $transport);

    $result = app(CertificateCheck::class)->forApplication($application);

    expect(certificateProbes($transport))->toHaveCount(1)
        ->and($result['domains']['d1.example.com']['state'])->toBe('valid')
        ->and($result['domains']['d45.example.com']['state'])->toBe('valid')
        ->and($result['domains']['d45.example.com']['expires_at'])->toBe(CarbonImmutable::parse('2026-10-20 12:00:00')->addDays(40)->toIso8601String())
        ->and($result['checked_at'])->toBe($earlier)
        ->and(app(CertificateCheck::class)->stale($application))->toBeTrue();
});

test('a wildcard is never probed', function () {
    $application = certificateApp(['domain' => 'www.example.com', 'additional_domains' => ['*.example.com']]);
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($application->server, $transport);

    expect(certificateProbes($transport))->toHaveCount(1)
        ->and(certificateProbes($transport)[0])->toContain("'www.example.com'")
        ->and(certificateProbes($transport)[0])->not->toContain('*.example.com');

    $wildcard = certificateApp(['domain' => '*.shop.example.com']);
    $quiet = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($wildcard->server, $quiet);

    expect(certificateProbes($quiet))->toBe([]);
});

test('expiry lines are parsed and a missing certificate is null', function () {
    $parsed = (new CertificateProbe)->parse(certificateOutput([
        '@@cert a.example.com notAfter=Jan  8 12:00:00 2027 GMT',
        '@@cert b.example.com none',
        '@@cert evil.example.com notAfter=Jan  8 12:00:00 2027 GMT',
        'garbage',
    ]), ['a.example.com', 'b.example.com']);

    expect(array_keys($parsed))->toBe(['a.example.com', 'b.example.com'])
        ->and($parsed['a.example.com']?->toIso8601String())->toBe('2027-01-08T12:00:00+00:00')
        ->and($parsed['b.example.com'])->toBeNull();
});

test('each domain gets a state the page can show', function (?int $days, string $state, ?int $whole, string $label, string $title) {
    Notification::fake();
    $application = certificateApp();
    $line = $days === null ? '@@cert a.example.com none' : '@@cert a.example.com ' . certificateNotAfter($days);

    certificateRun($application->server, (new FakeSSHTransport)->respondTo('@@cert', certificateOutput([$line])));

    $domain = app(CertificateCheck::class)->forApplication($application)['domains']['a.example.com'];

    expect($domain['state'])->toBe($state)
        ->and($domain['days'])->toBe($whole)
        ->and($domain['label'])->toBe($label)
        ->and($domain['title'])->toBe($title);
})->with([
    'valid' => [30, 'valid', 30, 'TLS', 'Valid until Nov 19, 2026 at 12:00 UTC, checked now'],
    'five days' => [5, 'expiring', 5, 'Expires in 5 days', 'Caddy should have renewed this by now. Check a.example.com still points at 203.0.113.9.'],
    'one day' => [1, 'expiring', 1, 'Expires tomorrow', 'Caddy should have renewed this by now. Check a.example.com still points at 203.0.113.9.'],
    'expired' => [-1, 'expired', -1, 'Expired', 'Caddy should have renewed this by now. Check a.example.com still points at 203.0.113.9.'],
    'none' => [null, 'none', null, 'No certificate', 'Caddy has no certificate for this name yet. Point its DNS at 203.0.113.9 and Caddy requests one.'],
]);

test('the title says when the certificate was checked, as of now', function () {
    Notification::fake();
    $application = certificateApp();

    certificateRun($application->server, (new FakeSSHTransport)->respondTo('@@cert', certificateOutput(['@@cert a.example.com ' . certificateNotAfter(30)])));

    $this->travel(3)->hours();

    expect(app(CertificateCheck::class)->forApplication($application)['domains']['a.example.com']['title'])->toBe('Valid until Nov 19, 2026 at 12:00 UTC, checked today 12:00')
        ->and(app(CertificateCheck::class)->forApplication($application)['domains']['a.example.com']['days'])->toBe(29);
});

test('an expiring certificate is logged and emailed once a day per domain', function () {
    Notification::fake();
    $application = certificateApp(['additional_domains' => ['b.example.com']]);
    $owner = $application->team->members()->first();
    $transport = (new FakeSSHTransport)->respondTo('@@cert', certificateOutput([
        '@@cert a.example.com ' . certificateNotAfter(5),
        '@@cert b.example.com ' . certificateNotAfter(-2),
    ]));

    certificateRun($application->server, $transport);
    certificateRun($application->server, $transport);

    expect(Activity::query()->where('description', 'certificate_expiring')->count())->toBe(2)
        ->and(Activity::query()->where('description', 'certificate_expiring')->pluck('properties')->map->toArray()->sortBy('domain')->values()->all())->toBe([
            ['domain' => 'a.example.com', 'days' => 5],
            ['domain' => 'b.example.com', 'days' => -2],
        ]);

    Notification::assertSentToTimes($owner, CertificateExpiringNotification::class, 2);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:30:00'));

    certificateRun($application->server, $transport);

    expect(Activity::query()->where('description', 'certificate_expiring')->count())->toBe(4);

    Notification::assertSentToTimes($owner, CertificateExpiringNotification::class, 4);
});

test('a valid or missing certificate sends nothing', function () {
    Notification::fake();
    $application = certificateApp(['additional_domains' => ['b.example.com']]);

    certificateRun($application->server, (new FakeSSHTransport)->respondTo('@@cert', certificateOutput([
        '@@cert a.example.com ' . certificateNotAfter(8),
        '@@cert b.example.com none',
    ])));

    expect(Activity::query()->where('description', 'certificate_expiring')->count())->toBe(0);

    Notification::assertNothingSent();
});

test('a team member who turned off server problems is not emailed, and the row is still written', function () {
    Notification::fake();
    $application = certificateApp();
    $owner = $application->team->members()->first();
    $owner->forceFill(['notification_preferences' => ['server_alerts' => false]])->save();

    certificateRun($application->server, (new FakeSSHTransport)->respondTo('@@cert', certificateOutput(['@@cert a.example.com ' . certificateNotAfter(2)])));

    expect(Activity::query()->where('description', 'certificate_expiring')->count())->toBe(1);

    Notification::assertNothingSent();
});

test('a stopped app is still checked, a server that cannot be reached is skipped quietly', function () {
    $application = certificateApp(['power_state' => PowerState::Stopped]);
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($application->server, $transport);

    expect(certificateProbes($transport)[0])->toContain("'a.example.com'");

    Cache::put(CertificateCheck::cacheKey($application), ['domains' => ['a.example.com' => ['expires_at' => null, 'checked_at' => now()->toIso8601String()]]], now()->addDays(3));
    $before = Cache::get(CertificateCheck::cacheKey($application));

    certificateRun($application->server, (new FakeSSHTransport)->refuseConnections());

    expect(Cache::get(CertificateCheck::cacheKey($application)))->toBe($before);
});

test('a server that is not active or whose host key changed is not probed', function (array $attributes) {
    $application = certificateApp();
    $application->server->forceFill($attributes)->save();
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($application->server, $transport);

    expect($transport->commands())->toBe([]);
})->with([
    'still provisioning' => [['status' => ServerStatus::Provisioning]],
    'a changed host key' => [['pending_host_key_fingerprint' => 'SHA256:changed']],
]);

test('a deleted app is not probed', function () {
    $application = certificateApp();
    $application->delete();
    $transport = (new FakeSSHTransport)->respondTo('@@cert', '');

    certificateRun($application->server, $transport);

    expect(certificateProbes($transport))->toBe([]);
});

test('results go stale after six hours, or ten minutes when a certificate was missing', function (?int $expiresInDays, int $minutesAgo, bool $stale) {
    $application = certificateApp();
    Cache::put(CertificateCheck::cacheKey($application), ['domains' => ['a.example.com' => [
        'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays)->toIso8601String(),
        'checked_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
    ]]], now()->addDays(3));

    expect(app(CertificateCheck::class)->stale($application))->toBe($stale);
})->with([
    'a valid certificate checked an hour ago' => [30, 60, false],
    'a valid certificate checked seven hours ago' => [30, 7 * 60, true],
    'a missing certificate checked five minutes ago' => [null, 5, false],
    'a missing certificate checked eleven minutes ago' => [null, 11, true],
]);

test('an app never checked, or with a domain added since, is stale; one with no domain never is', function () {
    $application = certificateApp(['additional_domains' => ['b.example.com']]);

    expect(app(CertificateCheck::class)->stale($application))->toBeTrue();

    Cache::put(CertificateCheck::cacheKey($application), ['domains' => ['a.example.com' => ['expires_at' => now()->addDays(30)->toIso8601String(), 'checked_at' => now()->toIso8601String()]]], now()->addDays(3));

    expect(app(CertificateCheck::class)->stale($application))->toBeTrue()
        ->and(app(CertificateCheck::class)->stale(certificateApp(['domain' => null])))->toBeFalse();
});

test('opening the app page queues a check only when needed', function (string $case, bool $queued) {
    Queue::fake();
    $application = certificateApp($case === 'no domains' ? ['domain' => null] : []);

    if ($case === 'fresh') {
        Cache::put(CertificateCheck::cacheKey($application), ['domains' => ['a.example.com' => ['expires_at' => now()->addDays(30)->toIso8601String(), 'checked_at' => now()->toIso8601String()]]], now()->addDays(3));
    }

    $this->actingAs($application->team->members()->first())
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertOk();

    $queued ? Queue::assertPushed(CheckCertificates::class, 1) : Queue::assertNotPushed(CheckCertificates::class);
})->with([
    'stale' => ['stale', true],
    'fresh' => ['fresh', false],
    'no domains' => ['no domains', false],
]);

test('the app page carries the states', function () {
    $application = certificateApp(['additional_domains' => ['b.example.com']]);
    Cache::put(CertificateCheck::cacheKey($application), ['domains' => [
        'a.example.com' => ['expires_at' => now()->addDays(3)->toIso8601String(), 'checked_at' => now()->toIso8601String()],
        'b.example.com' => ['expires_at' => null, 'checked_at' => now()->toIso8601String()],
    ]], now()->addDays(3));

    $this->actingAs($application->team->members()->first())
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.certificates', fn($certificates): bool => $certificates['domains']['a.example.com']['state'] === 'expiring'
            && $certificates['domains']['a.example.com']['label'] === 'Expires in 3 days'
            && $certificates['domains']['b.example.com']['state'] === 'none'
            && array_keys($certificates['domains']) === ['a.example.com', 'b.example.com']));
});

test('an app page with nothing checked yet carries no states', function () {
    Queue::fake();
    $application = certificateApp();

    $this->actingAs($application->team->members()->first())
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.certificates', null));
});

test('a deploy that routes traffic checks certificates ninety seconds later', function () {
    Queue::fake([CheckCertificates::class]);
    $application = certificateApp(['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.5.5', 'run_migrations' => false, 'base_port' => 8000]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = (new FakeSSHTransport)->respondTo('df /', "40\n");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261020-120000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    Queue::assertPushed(CheckCertificates::class, fn(CheckCertificates $job): bool => $job->server->is($application->server)
        && $job->delay instanceof DateTimeInterface
        && CarbonImmutable::parse($job->delay)->equalTo(now()->addSeconds(90)));
});

test('certificates are checked every morning', function () {
    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:check-certificates');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 5 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('the morning check queues every active server whose host key is trusted', function () {
    Queue::fake();
    $active = Server::factory()->create(['status' => ServerStatus::Active]);
    Server::factory()->create(['status' => ServerStatus::Provisioning]);
    Server::factory()->create(['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:changed']);

    collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:check-certificates')->run(app());

    Queue::assertPushed(CheckCertificates::class, 1);
    Queue::assertPushed(CheckCertificates::class, fn(CheckCertificates $job): bool => $job->server->is($active));
});

test('checks of one server never queue twice', function () {
    $job = new CheckCertificates(Server::factory()->create());

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $job->server->getKey())
        ->and($job->uniqueFor)->toBe(900)
        ->and($job->timeout)->toBe(600)
        ->and($job->tries)->toBe(1)
        ->and($job->queue)->toBe('server-tasks');
});

test('the attention list and feed name an expiring certificate', function (int $days, string $summary, string $feed) {
    $application = certificateApp();

    activity()->performedOn($application)->withProperties(['domain' => 'a.example.com', 'days' => $days])->log('certificate_expiring');

    $attention = collect((new FleetSnapshot($application->team))->attention())->firstWhere('subject', 'Shop');
    $row = collect(app(ActivityFeed::class)->forTeam($application->team))->sole();

    expect($attention)->toBe(['severity' => 'warning', 'subject' => 'Shop', 'summary' => $summary, 'href' => route('servers.applications.show', [$application->server_id, $application])])
        ->and(implode('', array_column($row['parts'], 'text')))->toBe($feed)
        ->and(array_column(array_filter($row['parts'], fn(array $part): bool => $part['mono']), 'text'))->toBe(['a.example.com', 'Shop']);
})->with([
    'days ahead' => [5, 'certificate expires in 5 days', 'Certificate for a.example.com on Shop expires in 5 days'],
    'tomorrow' => [1, 'certificate expires tomorrow', 'Certificate for a.example.com on Shop expires tomorrow'],
    'today' => [0, 'certificate expires today', 'Certificate for a.example.com on Shop expires today'],
    'expired' => [-2, 'certificate expired', 'Certificate for a.example.com on Shop has expired'],
]);

test('an alert from more than a day ago no longer needs attention', function () {
    $application = certificateApp();

    activity()->performedOn($application)->withProperties(['domain' => 'a.example.com', 'days' => 5])->log('certificate_expiring');

    $this->travel(25)->hours();

    expect(collect((new FleetSnapshot($application->team))->attention())->firstWhere('subject', 'Shop'))->toBeNull();
});

test('the mail names the domain and what to check', function (int $days, string $subject) {
    $application = certificateApp();
    $mail = (new CertificateExpiringNotification($application, 'a.example.com', $days))->toMail($application->team->members()->first());

    expect($mail->subject)->toBe($subject)
        ->and($mail->introLines)->toBe([
            'Caddy on web-1 renews certificates well before they expire, so this one could not be renewed.',
            'Check that a.example.com still points at 203.0.113.9 and that ports 80 and 443 are open.',
        ])
        ->and($mail->actionText)->toBe('Open Shop')
        ->and($mail->actionUrl)->toBe(route('servers.applications.show', [$application->server_id, $application]));
})->with([
    'days ahead' => [5, 'The certificate for a.example.com expires in 5 days'],
    'tomorrow' => [1, 'The certificate for a.example.com expires tomorrow'],
    'today' => [0, 'The certificate for a.example.com expires today'],
    'expired' => [-2, 'The certificate for a.example.com has expired'],
]);

test('a probe that fails for a reason other than the connection is thrown', function () {
    $application = certificateApp();
    $transport = (new FakeSSHTransport)->onCommand('@@cert', fn() => throw new RuntimeException('The disk is full.'));

    expect(fn() => certificateRun($application->server, $transport))->toThrow(RuntimeException::class, 'The disk is full.');
});

test('a connection that drops between batches keeps what the finished batches found', function () {
    Notification::fake();
    $domains = certificateDomains(50);
    $application = certificateApp(['domain' => $domains[0], 'additional_domains' => array_slice($domains, 1)]);
    $transport = (new FakeSSHTransport)
        ->respondTo('@@cert', certificateOutput(['@@cert d1.example.com ' . certificateNotAfter(30)]))
        ->dropConnectionOn("'d41.example.com'");

    certificateRun($application->server, $transport);

    $result = app(CertificateCheck::class)->forApplication($application);

    expect(certificateProbes($transport))->toHaveCount(2)
        ->and(array_keys($result['domains']))->toBe(['d1.example.com'])
        ->and($result['domains']['d1.example.com']['state'])->toBe('valid');
});
