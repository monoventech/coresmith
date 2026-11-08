<?php

declare(strict_types=1);

use App\Services\Updates\FeedDocument;
use App\Services\Updates\InvalidFeed;
use App\Services\Updates\Version;

function updatesFeedRefusal(array $data): string
{
    try {
        FeedDocument::fromArray($data);
    } catch (InvalidFeed $e) {
        return $e->getMessage();
    }

    return 'accepted';
}

test('a feed document survives a round trip in the contract shape', function () {
    $document = FeedDocument::fromArray([
        'schema' => 1, 'latest' => '1.0.5', 'minimum' => '1.0.0', 'withdrawn' => ['1.0.3'],
        'changelog_url' => 'https://github.com/monoven/coresmith/releases/tag/v1.0.5',
        'summary' => "Faster deploys.\nCalmer logs.", 'released_at' => '2026-11-02', 'channel' => 'stable', 'future' => 'ignored',
    ]);

    expect($document->toArray())->toBe([
        'schema' => 1, 'latest' => '1.0.5', 'minimum' => '1.0.0', 'withdrawn' => ['1.0.3'],
        'changelog_url' => 'https://github.com/monoven/coresmith/releases/tag/v1.0.5',
        'summary' => "Faster deploys.\nCalmer logs.", 'released_at' => '2026-11-02', 'channel' => 'stable',
    ])->and($document->isWithdrawn(Version::parse('1.0.3')))->toBeTrue()
        ->and($document->isWithdrawn(Version::parse('1.0.5')))->toBeFalse();
});

test('the smallest valid feed is a latest version', function () {
    expect(FeedDocument::fromArray(['latest' => '1.0.0'])->toArray())->toBe([
        'schema' => 1, 'latest' => '1.0.0', 'minimum' => null, 'withdrawn' => [],
        'changelog_url' => null, 'summary' => null, 'released_at' => null, 'channel' => 'stable',
    ]);
});

test('a document reads back what it writes', function () {
    $written = FeedDocument::fromArray(['latest' => 'v2.1.0-beta.3', 'channel' => 'beta', 'withdrawn' => ['v2.0.1'], 'released_at' => '2026-11-02T09:30:00+01:00'])->toArray();

    expect(FeedDocument::fromArray($written)->toArray())->toBe($written)
        ->and($written['latest'])->toBe('2.1.0-beta.3')
        ->and($written['withdrawn'])->toBe(['2.0.1'])
        ->and($written['released_at'])->toBe('2026-11-02T09:30:00+01:00');
});

test('a feed that breaks the contract is refused with the field it got wrong', function (array $data, string $field) {
    expect(fn() => FeedDocument::fromArray($data))->toThrow(InvalidFeed::class, $field);
})->with([
    'no latest' => [[], 'latest'],
    'latest not a version' => [['latest' => 'newest'], 'latest'],
    'a pre-release latest on the stable channel' => [['latest' => '1.1.0-beta.1'], 'latest'],
    'a newer schema' => [['latest' => '1.0.0', 'schema' => 2], 'schema'],
    'an unknown channel' => [['latest' => '1.0.0', 'channel' => 'nightly'], 'channel'],
    'withdrawn not a list' => [['latest' => '1.0.0', 'withdrawn' => '1.0.1'], 'withdrawn'],
    'a withdrawn entry that is not a version' => [['latest' => '1.0.0', 'withdrawn' => ['1.0.1', 'oops']], 'withdrawn'],
    'too many withdrawn' => [['latest' => '1.0.0', 'withdrawn' => array_map(fn($i) => "0.0.{$i}", range(1, 201))], 'withdrawn'],
    'minimum not a version' => [['latest' => '1.0.0', 'minimum' => 'old'], 'minimum'],
]);

test('each refusal says what is wrong without repeating what the feed sent', function (array $data, string $message) {
    expect(updatesFeedRefusal($data))->toBe($message);
})->with([
    'no latest' => [['latest' => null], 'latest is missing.'],
    'latest not a version' => [['latest' => '<script>'], 'latest is not a version like 1.2.3.'],
    'latest not a string' => [['latest' => 102], 'latest is not a version like 1.2.3.'],
    'a pre-release on stable' => [['latest' => '1.1.0-beta.1', 'channel' => 'stable'], 'latest is a pre-release, which the stable channel never offers.'],
    'minimum' => [['latest' => '1.0.0', 'minimum' => '<b>old</b>'], 'minimum is not a version like 1.2.3.'],
    'withdrawn not a list' => [['latest' => '1.0.0', 'withdrawn' => ['a' => '1.0.1']], 'withdrawn is not a list of versions.'],
    'withdrawn too long' => [['latest' => '1.0.0', 'withdrawn' => array_fill(0, 201, '1.0.1')], 'withdrawn lists more than 200 versions.'],
    'withdrawn entry' => [['latest' => '1.0.0', 'withdrawn' => [['1.0.1']]], 'withdrawn holds something that is not a version like 1.2.3.'],
    'schema' => [['latest' => '1.0.0', 'schema' => '1'], 'schema is newer than this version of Coresmith understands.'],
    'channel' => [['latest' => '1.0.0', 'channel' => ['stable']], 'channel is neither stable nor beta.'],
    'channel that is not text' => [['latest' => '1.0.0', 'channel' => true], 'channel is neither stable nor beta.'],
]);

test('a beta feed may offer a pre-release', function () {
    expect(FeedDocument::fromArray(['latest' => '1.1.0-beta.1', 'channel' => 'beta'])->latest->isPrerelease())->toBeTrue();
});

test('exactly 200 withdrawn versions are accepted', function () {
    expect(FeedDocument::fromArray(['latest' => '1.0.0', 'withdrawn' => array_map(fn($i) => "0.0.{$i}", range(1, 200))])->withdrawn)->toHaveCount(200);
});

test('anything that is not an https link is dropped rather than rendered', function (mixed $url) {
    expect(FeedDocument::fromArray(['latest' => '1.0.0', 'changelog_url' => $url])->changelogUrl)->toBeNull();
})->with(['javascript:alert(1)', 'http://example.com/notes', 'data:text/html,hi', '//example.com', 'ftp://example.com', ' https://example.com', [['https://example.com']]]);

test('a summary is plain text, trimmed and capped', function () {
    $document = FeedDocument::fromArray(['latest' => '1.0.0', 'summary' => "  line one\u{0007}\nline two  " . str_repeat('x', 2000)]);

    expect($document->summary)->toStartWith("line one\nline two")
        ->and(mb_strlen($document->summary))->toBe(1000)
        ->and($document->summary)->not->toContain("\u{0007}");
});

test('a summary keeps its line breaks and loses every other control and direction character', function () {
    $summary = FeedDocument::fromArray(['latest' => '1.0.0', 'summary' => "First\r\nSecond\tthird\u{202E}fourth\u{0000}"])->summary;

    expect($summary)->toBe("First\nSecondthirdfourth");
});

test('a summary that is empty or not text is dropped', function (mixed $summary) {
    expect(FeedDocument::fromArray(['latest' => '1.0.0', 'summary' => $summary])->summary)->toBeNull();
})->with([" \n ", [['text']], 42]);

test('an unreadable release date is dropped', function (mixed $date) {
    expect(FeedDocument::fromArray(['latest' => '1.0.0', 'released_at' => $date])->releasedAt)->toBeNull();
})->with(['last tuesday', '2026-02-30', '2026-11-02 garbage', 1793548800]);
