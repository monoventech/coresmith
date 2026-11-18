<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

test('an address that matches nothing shows the Coresmith error page', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn(AssertableInertia $page) => $page->component('Error')->where('status', 404)->where('expired', false));
});

test('the not found page for an address that matches nothing keeps the appearance the person chose', function (?string $appearance, bool $dark) {
    $request = $appearance === null ? $this : $this->withUnencryptedCookie('appearance', $appearance);
    $html = (string) $request->get('/no-such-page')->assertNotFound()->getContent();

    expect(str_contains($html, '<html lang="en"  class="dark">'))->toBe($dark);
})->with([
    'dark' => ['dark', true],
    'light' => ['light', false],
    'system' => ['system', false],
    'nothing chosen yet' => [null, true],
]);

test('a page your role does not cover shows the no access page', function () {
    Route::get('/error-page-forbidden', fn() => abort(403))->middleware('web');

    $this->actingAs(User::factory()->create())->get('/error-page-forbidden')
        ->assertForbidden()
        ->assertInertia(fn(AssertableInertia $page) => $page->component('Error')->where('status', 403)->where('expired', false));
});

test('a signed link that is too old or cut short shows the link expired page', function () {
    Route::get('/error-page-signed', fn() => 'opened')->middleware(['web', 'signed'])->name('error-page.signed');
    Route::getRoutes()->refreshNameLookups();

    $this->get(URL::signedRoute('error-page.signed'))->assertOk()->assertSee('opened');

    foreach ([URL::temporarySignedRoute('error-page.signed', now()->subMinute()), substr(URL::signedRoute('error-page.signed'), 0, -6)] as $link) {
        $this->get($link)
            ->assertForbidden()
            ->assertInertia(fn(AssertableInertia $page) => $page->component('Error')->where('status', 403)->where('expired', true));
    }
});

test('a server error shows the error page once debugging is off, and the debug page while it is on', function () {
    Route::get('/error-page-broken', fn() => throw new RuntimeException('The error page test broke this on purpose'))->middleware('web');

    config(['app.debug' => false]);
    $this->get('/error-page-broken')
        ->assertServerError()
        ->assertDontSee('The error page test broke this on purpose')
        ->assertInertia(fn(AssertableInertia $page) => $page->component('Error')->where('status', 500));

    config(['app.debug' => true]);
    $this->get('/error-page-broken')->assertServerError()->assertSee('The error page test broke this on purpose');
});

test('an inertia visit to a missing page gets the error page as a page, in place of a popup', function () {
    $this->withHeaders(['X-Inertia' => 'true'])->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 404);
});

test('a request that wants json keeps the plain json error', function () {
    $response = $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and($response->json('component'))->toBeNull();
});

test('a form sent to a missing address keeps the plain error, since there is no page to show it on', function () {
    Route::post('/error-page-form', fn() => abort(404))->middleware('web');

    $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/error-page-form')->assertNotFound();

    expect($response->headers->get('X-Inertia'))->toBeNull();
});

test('other statuses keep their plain pages', function () {
    Route::get('/error-page-gone', fn() => abort(410))->middleware('web');

    $response = $this->get('/error-page-gone')->assertStatus(410);

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and((string) $response->getContent())->not->toContain('"component":"Error"')->not->toContain('&quot;component&quot;:&quot;Error&quot;');
});

test("a refused page names nothing the person can't see", function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for(Team::factory()->owned()->create())->create(['name' => 'secret-box']);
    $application = Application::factory()->for($server->team)->for($server)->create(['name' => 'secret-shop']);

    foreach (["/servers/{$server->id}", "/servers/{$server->id}/applications/{$application->id}"] as $address) {
        $response = $this->actingAs($team->owner)->get($address)->assertForbidden();

        expect($response->headers->get('Location'))->toBeNull()
            ->and($response->getContent())->not->toContain('secret-box')
            ->and($response->getContent())->not->toContain('secret-shop');
    }
});
