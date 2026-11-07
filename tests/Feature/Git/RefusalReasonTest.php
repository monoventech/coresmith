<?php

declare(strict_types=1);

use App\Services\Git\Providers\BitbucketClient;
use App\Services\Git\Providers\GitLabClient;
use App\Services\Git\Providers\OAuthGitClient;
use App\Services\Git\RefusalReason;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a refusal says what the provider said, then its description, then its code, then the status', function (array|string $body, int $status, string $expected) {
    $response = new Response(new PsrResponse($status, [], is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body));

    expect(RefusalReason::for($response))->toBe($expected);
})->with([
    'a message' => [['message' => 'Bad credentials', 'error' => 'access_denied', 'error_description' => 'The token was revoked'], 401, 'Bad credentials'],
    'a description' => [['error' => 'invalid_grant', 'error_description' => 'The grant is invalid'], 400, 'The grant is invalid'],
    'a code' => [['error' => 'invalid_grant'], 400, 'invalid_grant'],
    'an empty message' => [['message' => '', 'error' => 'access_denied'], 422, 'access_denied'],
    'a message that is not text' => [['message' => ['nested'], 'error' => 'access_denied'], 422, 'access_denied'],
    'a message that is a number' => [['message' => 404], 404, 'HTTP 404'],
    'nothing to say' => [[], 503, 'HTTP 503'],
    'a json list' => [['not', 'an', 'object'], 500, 'HTTP 500'],
    'a body that is not json' => ['<html>bad gateway</html>', 502, 'HTTP 502'],
    'no body at all' => ['', 504, 'HTTP 504'],
]);

test('the shared oauth base and its providers do not depend on the github classes', function (string $class) {
    $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

    expect($source)->not->toContain('Services\\Git\\GitHub');
})->with([OAuthGitClient::class, GitLabClient::class, BitbucketClient::class]);

test('a reason becomes a sentence of at most three hundred characters', function (string $reason, string $sentence) {
    expect(RefusalReason::sentence($reason))->toBe($sentence);
})->with([
    'no full stop' => ['Forbidden', 'Forbidden.'],
    'a full stop already' => ['Not permitted.', 'Not permitted.'],
    'a question' => ['Who are you?', 'Who are you?'],
    'spaces around it' => ['  Forbidden  ', 'Forbidden.'],
    'too long' => [str_repeat('a', 400), str_repeat('a', 300) . '...'],
]);
