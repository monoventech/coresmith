<?php

declare(strict_types=1);

use App\Enums\Edition;
use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

function editionOwner(): User
{
    $team = Team::factory()->create();
    $team->members()->attach($team->owner_id, ['role' => TeamRole::Owner->value]);

    return User::query()->findOrFail($team->owner_id)->fresh();
}

test('the edition is self-hosted unless the environment says cloud', function () {
    expect(Edition::current())->toBe(Edition::SelfHosted)
        ->and(Edition::current()->label())->toBe('Self-hosted')
        ->and(Edition::current()->isSelfHosted())->toBeTrue()
        ->and(Edition::current()->isCloud())->toBeFalse();

    config(['coresmith.edition' => 'cloud']);

    expect(Edition::current())->toBe(Edition::Cloud)
        ->and(Edition::current()->label())->toBe('Coresmith Cloud')
        ->and(Edition::current()->isCloud())->toBeTrue()
        ->and(Edition::current()->isSelfHosted())->toBeFalse();
});

test('an unknown edition stops the panel instead of guessing', function () {
    config(['coresmith.edition' => 'clod']);

    expect(fn() => Edition::current())->toThrow(InvalidArgumentException::class, 'CORESMITH_EDITION must be self-hosted or cloud, not clod.');
});

test('a panel with an unknown edition refuses to start', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'about', '--only=environment'], base_path(), ['CORESMITH_EDITION' => 'clod']);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput() . $process->getErrorOutput())->toContain('CORESMITH_EDITION must be self-hosted or cloud, not clod.');
});

test('self-hosted has no billing page', function () {
    $this->actingAs(editionOwner())->get(route('settings.billing'))->assertNotFound();
});

test('cloud has a billing page', function () {
    config(['coresmith.edition' => 'cloud']);

    $this->actingAs(editionOwner())->get(route('settings.billing'))->assertOk();
});

test('cashier registers no routes of its own', function () {
    expect(Route::has('cashier.payment'))->toBeFalse();

    $this->postJson('/stripe/webhook', ['type' => 'customer.deleted'])->assertNotFound();
});

test('cloud never shows the setup holding page or the installer', function () {
    config(['fortify.features' => [Features::resetPasswords(), Features::emailVerification()]]);

    $this->get(route('login'))->assertStatus(503);

    config(['coresmith.edition' => 'cloud']);

    $this->get(route('login'))->assertOk();
    $this->get('/install?token=anything')->assertNotFound();
});

test('on cloud no customer can change the panel itself', function () {
    config(['coresmith.edition' => 'cloud']);
    $owner = editionOwner();

    $this->actingAs($owner)->put(route('settings.instance.update'), ['instance_name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($owner)->post(route('settings.backups.store'))->assertForbidden();
    $this->actingAs($owner)->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('can.manageInstance', false)->where('edition', 'cloud'));

    expect(InstanceSetting::get('instance_name'))->toBeNull();
});

test('on cloud no customer manages the instance', function () {
    $owner = editionOwner();

    expect($owner->managesInstance())->toBeTrue()
        ->and(User::query()->instanceOwners()->pluck('id')->all())->toBe([$owner->id]);

    config(['coresmith.edition' => 'cloud']);

    expect($owner->managesInstance())->toBeFalse()
        ->and(User::query()->instanceOwners()->count())->toBe(0);
});

test('on cloud the panel is named for the product, not by a customer', function () {
    InstanceSetting::put('instance_name', 'Acme ops');

    $this->get('/')->assertInertia(fn($page) => $page->where('appName', 'Acme ops'));

    config(['coresmith.edition' => 'cloud']);

    $this->get('/')->assertInertia(fn($page) => $page->where('appName', 'Coresmith'));
});

test('every page knows the edition', function () {
    $this->get('/')->assertInertia(fn($page) => $page->where('edition', 'self-hosted')->missing('billingEnabled'));

    config(['coresmith.edition' => 'cloud']);

    $this->get('/')->assertInertia(fn($page) => $page->where('edition', 'cloud'));
});

test('the palette offers billing on cloud only', function () {
    $user = User::factory()->create();

    expect(collect((new CommandPalette($user, null))->commands())->firstWhere('label', 'Billing'))->toBeNull();

    config(['coresmith.edition' => 'cloud']);

    expect(collect((new CommandPalette($user, null))->commands())->firstWhere('label', 'Billing'))
        ->toBe(['label' => 'Billing', 'icon' => 'credit-card', 'hint' => 'Coresmith Cloud', 'href' => route('settings.billing')]);
});

test('only the edition enum reads the edition from config', function () {
    $readers = collect([...File::allFiles(app_path()), ...File::allFiles(base_path('routes'))])
        ->filter(fn(SplFileInfo $file): bool => str_contains($file->getContents(), 'coresmith.edition'))
        ->map(fn(SplFileInfo $file): string => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($readers)->toBe(['app' . DIRECTORY_SEPARATOR . 'Enums' . DIRECTORY_SEPARATOR . 'Edition.php']);
});

test('nothing reads the old billing switch', function () {
    $files = collect([app_path(), config_path(), base_path('routes'), resource_path('js')])
        ->flatMap(fn(string $path) => File::allFiles($path))
        ->map(fn(SplFileInfo $file): string => $file->getPathname())
        ->merge([base_path('.env.example'), base_path('.env.e2e'), base_path('.env.e2e-install'), base_path('.env.demo.example')]);

    expect($files->filter(fn(string $path): bool => str_contains(strtolower((string) file_get_contents($path)), 'billing_enabled'))->values()->all())->toBe([]);
});
